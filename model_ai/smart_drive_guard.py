import cv2
import numpy as np
import requests
import time
import json
from datetime import datetime

import mediapipe as mp
from mediapipe.tasks import python as mp_python
from mediapipe.tasks.python import vision as mp_vision

# ==================== ตั้งค่าระบบ (CONFIG) ====================
# DEVICE_IP = "10.170.65.51"
DEVICE_IP = "10.179.216.51"

STREAM_URL = f"http://{DEVICE_IP}:81/stream"        # พอร์ตกล้อง (สตรีมวิดีโอ)
BUZZER_ON_URL = f"http://{DEVICE_IP}:82/buzzer/on"    # สั่งให้บัซเซอร์เริ่มดัง
BUZZER_OFF_URL = f"http://{DEVICE_IP}:82/buzzer/off"  # สั่งให้บัซเซอร์หยุดดัง

LARAVEL_WEBHOOK_URL = "http://10.179.216.154:8000/api/alerts"  # 🔴 แก้จาก localhost เป็น IP ของเครื่องที่รัน php artisan serve --host=0.0.0.0
IOT_API_KEY = "smart-iot-2026-secretkey"

TRIP_ID = "110"
DRIVER_ID = "50"
DEVICE_ID = "19"

# ---- MediaPipe Face Landmarker ----
MODEL_PATH = "face_landmarker.task"  # ดาวน์โหลดจากลิงก์ในคำอธิบาย แล้ววางไว้โฟลเดอร์เดียวกัน

# ---- threshold: การหลับตา (จาก blendshape eyeBlinkLeft/Right, ค่า 0-1) ----
EYE_BLINK_SCORE_THRESHOLD = 0.5   # ค่ามากกว่านี้ถือว่าหลับตา (0.5 กำลังดี ปรับได้ 0.4-0.6)

# ---- threshold: การหันหน้า/ไม่มองถนน (จากมุม yaw ที่คำนวณจาก facial transformation matrix) ----
HEAD_YAW_THRESHOLD_DEG = 25.0     # หันเกินกี่องศาถึงถือว่า "ไม่มองถนน" (ปรับได้ 20-30)

# ---- threshold เวลา/จำนวนครั้ง (ใช้ร่วมกันทั้งหลับตาและหันหน้า) ----
INATTENTIVE_SECONDS_THRESHOLD = 2.5
ALERT_AFTER_N_TIMES = 3
EVENT_RESET_WINDOW_SECONDS = 60

BUZZER_CMD_TIMEOUT_SECONDS = 2.5

LARAVEL_TIMEOUT_SECONDS = 5
LARAVEL_MAX_RETRIES = 2
LARAVEL_RETRY_DELAY_SECONDS = 0.5

FAILED_ALERTS_LOG = "failed_alerts.jsonl"
# ==========================================================

# ---- ตั้งค่า MediaPipe Face Landmarker (ต้องเปิด output_face_blendshapes
#      และ output_facial_transformation_matrixes เพื่อเอาไปคำนวณหลับตา/หันหน้า) ----
base_options = mp_python.BaseOptions(model_asset_path=MODEL_PATH)
landmarker_options = mp_vision.FaceLandmarkerOptions(
    base_options=base_options,
    running_mode=mp_vision.RunningMode.IMAGE,
    num_faces=1,
    output_face_blendshapes=True,
    output_facial_transformation_matrixes=True,
    min_face_detection_confidence=0.5,
    min_face_presence_confidence=0.5,
)
face_landmarker = mp_vision.FaceLandmarker.create_from_options(landmarker_options)


def rotation_matrix_to_angles(rotation_matrix):
    """แปลง rotation matrix (3x3) เป็นมุม pitch/yaw/roll หน่วยองศา"""
    x = np.arctan2(rotation_matrix[2, 1], rotation_matrix[2, 2])
    y = np.arctan2(-rotation_matrix[2, 0],
                    np.sqrt(rotation_matrix[2, 1] ** 2 + rotation_matrix[2, 2] ** 2))
    z = np.arctan2(rotation_matrix[1, 0], rotation_matrix[0, 0])
    return np.degrees([x, y, z])  # [pitch, yaw, roll]


def analyze_frame(frame_bgr):
    """
    ส่งเฟรมเข้า MediaPipe Face Landmarker แล้วสรุปผลออกมาเป็น:
      face_detected : bool
      eyes_closed   : bool   (มาจาก blendshape eyeBlinkLeft/Right)
      head_turned   : bool   (มาจากมุม yaw ที่หันซ้าย/ขวาเกิน threshold)
      yaw_deg       : float  (ไว้โชว์บนหน้าจอ debug)
    """
    rgb = cv2.cvtColor(frame_bgr, cv2.COLOR_BGR2RGB)
    mp_image = mp.Image(image_format=mp.ImageFormat.SRGB, data=rgb)
    result = face_landmarker.detect(mp_image)

    if not result.face_landmarks:
        return False, False, False, 0.0

    # ---- 1) เช็คหลับตา จาก blendshape score ----
    eyes_closed = False
    if result.face_blendshapes:
        scores = {b.category_name: b.score for b in result.face_blendshapes[0]}
        blink_left = scores.get("eyeBlinkLeft", 0.0)
        blink_right = scores.get("eyeBlinkRight", 0.0)
        avg_blink = (blink_left + blink_right) / 2.0
        eyes_closed = avg_blink >= EYE_BLINK_SCORE_THRESHOLD

    # ---- 2) เช็คหันหน้า/มองไม่ตรงถนน จาก facial transformation matrix ----
    head_turned = False
    yaw_deg = 0.0
    if result.facial_transformation_matrixes:
        matrix = np.array(result.facial_transformation_matrixes[0]).reshape(4, 4)
        rotation = matrix[:3, :3]
        pitch, yaw, roll = rotation_matrix_to_angles(rotation)
        yaw_deg = yaw
        head_turned = abs(yaw_deg) >= HEAD_YAW_THRESHOLD_DEG

    return True, eyes_closed, head_turned, yaw_deg


print(f"🔄 กำลังพยายามเชื่อมต่อไปยังกล้อง IoT: {STREAM_URL}")
cap = cv2.VideoCapture(STREAM_URL)
cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)

for i in range(5):
    if cap.isOpened():
        print("🟢 [SUCCESS] OpenCV เชื่อมต่อท่อวิดีโอสำเร็จแล้ว!")
        break
    print(f"⏳ บอร์ดยังไม่ส่งภาพมา กำลังลองใหม่ครั้งที่ {i+1}/5...")
    time.sleep(1.5)
else:
    print("\n❌ [FATAL ERROR] ไม่สามารถเปิดสตรีมมิ่งได้เลย!")
    exit()

# ---- สถานะของระบบ ----
inattentive_start = None
inattentive_reason = None  # "หลับตา" หรือ "ไม่มองถนน"
buzzer_is_ringing = False
closed_event_count = 0
last_event_timestamp = 0
last_event_type = "ง่วงนอน"

MAX_FAIL = 30
fail_count = 0


def turn_buzzer_on():
    global buzzer_is_ringing
    try:
        res = requests.get(BUZZER_ON_URL, headers={'Connection': 'close'}, timeout=BUZZER_CMD_TIMEOUT_SECONDS)
        print(f"🔊 สั่ง Buzzer ดัง: {res.text}")
        buzzer_is_ringing = True
    except Exception as e:
        print(f"❌ สั่ง Buzzer ดังไม่สำเร็จ: {e}")
        buzzer_is_ringing = False


def turn_buzzer_off():
    global buzzer_is_ringing
    try:
        res = requests.get(BUZZER_OFF_URL, headers={'Connection': 'close'}, timeout=BUZZER_CMD_TIMEOUT_SECONDS)
        print(f"🔇 สั่ง Buzzer หยุด: {res.text}")
    except Exception as e:
        print(f"❌ สั่ง Buzzer หยุดไม่สำเร็จ: {e}")
    finally:
        buzzer_is_ringing = False


def send_alert_to_laravel(alert_type: str):
    payload = {
        "trip_id": TRIP_ID,
        "driver_id": DRIVER_ID,
        "device_id": DEVICE_ID,
        "type": alert_type,
        "snapshot_url": "http://10.179.216.154:8000/storage/snapshots/default.jpg",
        "latitude": 13.7563,
        "longitude": 100.5018
    }
    headers = {
        "Content-Type": "application/json",
         "Accept": "application/json",
        "X-API-KEY": IOT_API_KEY
    }

    for attempt in range(1, LARAVEL_MAX_RETRIES + 1):
        try:
            res = requests.post(
                LARAVEL_WEBHOOK_URL,
                json=payload,
                headers=headers,
                timeout=LARAVEL_TIMEOUT_SECONDS
            )
            print(f"🚀 Laravel ตอบกลับมาว่า: HTTP {res.status_code} - {res.text}")
            return
        except Exception as e:
            print(f"❌ ยิงไป Laravel ไม่สำเร็จ (ครั้งที่ {attempt}/{LARAVEL_MAX_RETRIES}): {e}")
            if attempt < LARAVEL_MAX_RETRIES:
                time.sleep(LARAVEL_RETRY_DELAY_SECONDS)

    try:
        with open(FAILED_ALERTS_LOG, "a", encoding="utf-8") as f:
            f.write(json.dumps(
                {**payload, "failed_at": datetime.now().isoformat()},
                ensure_ascii=False
            ) + "\n")
        print(f"📝 บันทึก alert ที่ยิงไม่สำเร็จไว้ใน {FAILED_ALERTS_LOG} แล้ว (เผื่อส่งซ้ำทีหลัง)")
    except Exception as log_err:
        print(f"❌ [FATAL] เขียน log alert ที่ fail ก็ยังไม่สำเร็จ: {log_err}")


while True:
    cap.grab()
    ret, frame = cap.read()

    if not ret:
        fail_count += 1
        print(f"⚠️ เฟรมว่าง ({fail_count}/{MAX_FAIL})")
        if fail_count >= MAX_FAIL:
            print("🔄 กำลัง Reconnect กล้อง...")
            cap.release()
            time.sleep(2)
            cap = cv2.VideoCapture(STREAM_URL)
            cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
            fail_count = 0
        time.sleep(0.1)
        continue

    fail_count = 0
    frame = cv2.resize(frame, (320, 240))

    face_detected, eyes_closed, head_turned, yaw_deg = analyze_frame(frame)
    current_time = time.time()

    # รีเซ็ตตัวนับถ้าไม่มีเหตุการณ์เกิดขึ้นมานานแล้ว
    if closed_event_count > 0 and (current_time - last_event_timestamp) > EVENT_RESET_WINDOW_SECONDS:
        print(f"🔄 [RESET COUNTER] ไม่มีเหตุการณ์มา {EVENT_RESET_WINDOW_SECONDS} วิ -> รีเซ็ตตัวนับกลับเป็น 0")
        closed_event_count = 0

    is_inattentive = face_detected and (eyes_closed or head_turned)

    if is_inattentive:
        # ----- กำลังหลับตา หรือ หันหน้าไม่มองถนน -----
        reason = "หลับตา" if eyes_closed else "ไม่มองถนน (หันหน้า)"
        alert_type = "ง่วงนอน" if eyes_closed else "ไม่มองถนน"

        if inattentive_start is None:
            inattentive_start = current_time
            inattentive_reason = reason
            print(f"📥 [TIMING] ตรวจพบ: {reason} เริ่มจับเวลา...")
        else:
            elapsed_time = current_time - inattentive_start
            print(f"📥 [TIMING] {reason} ต่อเนื่อง -> {elapsed_time:.1f} / {INATTENTIVE_SECONDS_THRESHOLD} วินาที (yaw={yaw_deg:.1f}°)")

            if elapsed_time >= INATTENTIVE_SECONDS_THRESHOLD:
                turn_buzzer_on()
                last_event_type = alert_type

    else:
        # ----- ปกติแล้ว หรือ ตรวจไม่เจอใบหน้า -----
        if inattentive_start is not None:
            reason = "กลับมาปกติแล้ว" if face_detected else "ตรวจไม่เจอใบหน้า (Face Lost)"
            print(f"🔄 [RESET] ยกเลิกการจับเวลา! เพราะ -> {reason}")

            if buzzer_is_ringing:
                turn_buzzer_off()
                closed_event_count += 1
                last_event_timestamp = current_time
                print(f"✅ [EVENT DONE] เหตุการณ์ที่ {closed_event_count}/{ALERT_AFTER_N_TIMES} (ประเภท: {last_event_type})")

                if closed_event_count >= ALERT_AFTER_N_TIMES:
                    print(f"\n⚠️ [AI DETECTED] เหตุการณ์ซ้ำครบ {ALERT_AFTER_N_TIMES} ครั้ง! กำลังส่งสัญญาณแจ้งเตือนเข้าแอป...")
                    send_alert_to_laravel(last_event_type)
                    closed_event_count = 0

            inattentive_start = None
            inattentive_reason = None
        else:
            status = "เจอ" if face_detected else "ไม่เจอ"
            print(f"🟢 [NORMAL] ใบหน้า: {status} | ตา: {'หลับ' if eyes_closed else 'ลืม'} | yaw: {yaw_deg:.1f}° | นับสะสม: {closed_event_count}/{ALERT_AFTER_N_TIMES}")

    display_time = (current_time - inattentive_start) if inattentive_start is not None else 0.0

    cv2.putText(
        frame,
        f"{inattentive_reason or 'Normal'}: {display_time:.1f}/{INATTENTIVE_SECONDS_THRESHOLD}s | Count: {closed_event_count}/{ALERT_AFTER_N_TIMES}",
        (10, 30),
        cv2.FONT_HERSHEY_SIMPLEX,
        0.5,
        (0, 255, 0) if display_time < (INATTENTIVE_SECONDS_THRESHOLD / 2) else (0, 0, 255),
        2
    )
    cv2.putText(
        frame,
        f"yaw: {yaw_deg:.1f} deg",
        (10, 50),
        cv2.FONT_HERSHEY_SIMPLEX,
        0.45,
        (255, 255, 0),
        1
    )

    cv2.imshow('Smart Drive Guard - Realtime AI', frame)

    key = cv2.waitKey(1) & 0xFF
    if key == ord('q'):
        break
    elif key == ord('t'):
        print("🧪 [TEST] จำลองเหตุการณ์ -> เริ่มดังบัซเซอร์สั้นๆ แล้วนับเป็น 1 ครั้ง")
        turn_buzzer_on()
        time.sleep(1.5)
        turn_buzzer_off()
        closed_event_count += 1
        last_event_timestamp = time.time()
        print(f"🧪 [TEST] นับสะสม: {closed_event_count}/{ALERT_AFTER_N_TIMES}")
        if closed_event_count >= ALERT_AFTER_N_TIMES:
            print(f"\n⚠️ [TEST] ครบ {ALERT_AFTER_N_TIMES} ครั้ง! กำลังส่งสัญญาณแจ้งเตือนเข้าแอป...")
            send_alert_to_laravel("ง่วงนอน")
            closed_event_count = 0
    elif key == ord('r'):
        closed_event_count = 0
        turn_buzzer_off()
        print("🔄 [TEST] รีเซ็ตตัวนับกลับเป็น 0 แล้ว")

turn_buzzer_off()
cap.release()
cv2.destroyAllWindows()
