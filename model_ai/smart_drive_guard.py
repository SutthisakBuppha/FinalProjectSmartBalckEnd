import cv2
import numpy as np
import requests
import time
import json
import os
from datetime import datetime

import mediapipe as mp
from mediapipe.tasks import python as mp_python
from mediapipe.tasks.python import vision as mp_vision

# ==================== ตั้งค่าระบบ (CONFIG) ====================
# 🔴 จุดเดียวที่ยัง fix ไว้ -- ใช้ mDNS name แทน IP เพื่อไม่ต้องแก้ตาม WiFi ที่เปลี่ยน
# ถ้าเครื่องที่รัน Laravel ยังไม่ได้ตั้ง mDNS ไว้ ให้ใช้ IP จริงแทนชั่วคราว
# LARAVEL_BASE_URL = "http://laravel-pc.local:8000"
LARAVEL_BASE_URL = "http://DESKTOP-8H547TA.local:8000"

LARAVEL_WEBHOOK_URL = f"{LARAVEL_BASE_URL}/api/alerts"
DEVICE_LOOKUP_ENDPOINT = f"{LARAVEL_BASE_URL}/api/devices/lookup"
DEVICE_AUTO_DETECT_ENDPOINT = f"{LARAVEL_BASE_URL}/api/devices/auto-detect"

IOT_API_KEY = "smart-iot-2026-secretkey"
IOT_API_HEADERS = {"X-API-KEY": IOT_API_KEY}

# ==================== Serial Number ของกล้องที่เครื่องนี้ดูแล ====================
# 🟢 ดึงจากฐานข้อมูล Laravel (ตาราง devices) สดทุกครั้งที่รัน ไม่มีไฟล์ cache แล้ว
# (ตัด device_config.json ออก เพื่อกันปัญหาค่าเก่าค้าง ไม่ตรงกับ MAC จริงของบอร์ด)
# ลำดับความสำคัญ:
#   1) Environment variable DASHCAM_SERIAL_NUMBER (เหมาะกับรันเป็น service/Docker/มีกล้องหลายตัว)
#   2) ถาม Laravel ผ่าน endpoint /api/devices/auto-detect ให้ไปดึง serial_number ล่าสุด
#      ที่เคยลงทะเบียน/เชื่อมต่อในฐานข้อมูลมาใช้เอง (ตาม concept 1 เครื่อง PC ต่อกล้อง 1 ตัวเสมอ)

def fetch_serial_number_from_database() -> str | None:
    """ถาม Laravel ให้ไปดึง serial_number ของอุปกรณ์ที่เคยลงทะเบียนไว้แล้วในฐานข้อมูลมาใช้เอง"""
    try:
        res = requests.get(
            DEVICE_AUTO_DETECT_ENDPOINT,
            headers=IOT_API_HEADERS,
            timeout=10,
        )
        res.raise_for_status()
        payload = res.json()
        data = payload.get("data") or {}
        serial = data.get("serial_number")
        if serial:
            return str(serial).strip()
        print(f"⚠️ Laravel ตอบกลับมาแต่ไม่พบ serial_number: {payload.get('message')}")
        return None
    except Exception as e:
        print(f"❌ ดึง serial_number จากฐานข้อมูลไม่สำเร็จ: {e}")
        return None


def load_device_serial_number() -> str:
    # 1) Environment variable มาก่อนเสมอ (เผื่ออยากสั่ง override ตอนรัน)
    env_value = os.environ.get("DASHCAM_SERIAL_NUMBER")
    if env_value:
        print(f"🔧 ใช้ serial_number จาก environment variable DASHCAM_SERIAL_NUMBER = {env_value}")
        return env_value.strip()

    # 2) ดึงจากฐานข้อมูลสดทุกครั้ง (ไม่มีไฟล์ cache แล้ว -- กัน serial ค้างของเก่าไม่ตรงกับบอร์ดจริง)
    print("🔄 กำลังดึง serial_number ล่าสุดจากฐานข้อมูล...")
    serial = fetch_serial_number_from_database()

    if not serial:
        print("\n❌ [FATAL ERROR] ดึง serial_number จากฐานข้อมูลไม่สำเร็จ")
        print("   เช็คว่า: 1) Laravel รันอยู่ไหม  2) admin เพิ่มอุปกรณ์นี้ไว้ในระบบแล้วหรือยัง (ตาราง devices)")
        exit()

    print(f"✅ ใช้ serial_number จากฐานข้อมูล = {serial}")
    return serial


DEVICE_SERIAL_NUMBER = load_device_serial_number()
if not DEVICE_SERIAL_NUMBER:
    print("\n❌ [FATAL ERROR] ไม่มี serial_number ให้ใช้งาน -- ตั้งค่าผ่าน environment variable "
          "DASHCAM_SERIAL_NUMBER หรือให้ admin เพิ่มอุปกรณ์ในฐานข้อมูลก่อนรันใหม่")
    exit()

# ---- ตัวแปรที่ดึงมาจาก Laravel (ไม่ hardcode แล้ว) ----
DEVICE_ID = None
DRIVER_ID = None
TRIP_ID = None
DEVICE_IP = None
STREAM_URL = None
BUZZER_ON_URL = None
BUZZER_OFF_URL = None

DEVICE_IP_REFRESH_SECONDS = 30          # เช็ค IP/driver/trip ใหม่จาก Laravel ทุกๆ กี่วิ (เผื่อ ESP32 เปลี่ยน WiFi หรือเริ่ม trip ใหม่)
DEVICE_IP_FETCH_RETRY_DELAY = 3
DEVICE_IP_FETCH_MAX_RETRIES = 10        # ตอนเริ่มโปรแกรม ลองกี่ครั้งก่อนยอมแพ้

# ---- MediaPipe Face Landmarker ----
MODEL_PATH = "face_landmarker.task"  # ดาวน์โหลดจากลิงก์ในคำอธิบาย แล้ววางไว้โฟลเดอร์เดียวกัน

# ---- threshold: การหลับตา (จาก blendshape eyeBlinkLeft/Right, ค่า 0-1) ----
EYE_BLINK_SCORE_THRESHOLD = 0.5   # ค่ามากกว่านี้ถือว่าหลับตา (0.5 กำลังดี ปรับได้ 0.4-0.6)

# ---- threshold: การหันหน้า/ไม่มองถนน (จากมุม yaw ที่คำนวณจาก facial transformation matrix) ----
HEAD_YAW_THRESHOLD_DEG = 25.0     # หันเกินกี่องศาถึงถือว่า "ไม่มองถนน" (ปรับได้ 20-30)

INATTENTIVE_SECONDS_THRESHOLD = 2.5
ALERT_AFTER_N_TIMES = 3
EVENT_RESET_WINDOW_SECONDS = 60

BUZZER_CMD_TIMEOUT_SECONDS = 2.5

LARAVEL_TIMEOUT_SECONDS = 5
LARAVEL_MAX_RETRIES = 2
LARAVEL_RETRY_DELAY_SECONDS = 0.5

FAILED_ALERTS_LOG = "failed_alerts.jsonl"

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


def fetch_device_info():
    """
    ถาม Laravel ด้วย serial_number ของบอร์ดนี้ -> ได้ device_id, driver_id, trip_id, ip_address กลับมา
    คืนค่าเป็น dict ถ้าสำเร็จ, None ถ้าไม่สำเร็จ (เช่น ยังไม่ได้ลงทะเบียน/ยังไม่มี trip ที่กำลังทำอยู่)
    """
    try:
        res = requests.get(
            DEVICE_LOOKUP_ENDPOINT,
            params={"serial_number": DEVICE_SERIAL_NUMBER},
            headers=IOT_API_HEADERS,
            timeout=LARAVEL_TIMEOUT_SECONDS,
        )
        res.raise_for_status()
        payload = res.json()
        data = payload.get("data") or {}

        if not data.get("device_id"):
            print("⚠️ Laravel ตอบกลับมา แต่ไม่พบ device_id (serial number นี้ยังไม่ได้ลงทะเบียน)")
            return None
        if not data.get("ip_address"):
            print("⚠️ Laravel ตอบกลับมา แต่ยังไม่มี IP ของ ESP32 (ยังไม่เคย register)")
            return None

        return data
    except Exception as e:
        print(f"❌ ดึงข้อมูลอุปกรณ์จาก Laravel ไม่สำเร็จ: {e}")
        return None


def refresh_device_urls(info):
    """อัปเดต DEVICE_ID/DRIVER_ID/TRIP_ID/URL ทั้งหมดจากข้อมูลที่ Laravel ตอบกลับมา"""
    global DEVICE_ID, DRIVER_ID, TRIP_ID, DEVICE_IP, STREAM_URL, BUZZER_ON_URL, BUZZER_OFF_URL
    DEVICE_ID = info.get("device_id")
    DRIVER_ID = info.get("driver_id")
    TRIP_ID = info.get("trip_id")
    DEVICE_IP = info.get("ip_address")
    STREAM_URL = f"http://{DEVICE_IP}:81/stream"
    BUZZER_ON_URL = f"http://{DEVICE_IP}:82/buzzer/on"
    BUZZER_OFF_URL = f"http://{DEVICE_IP}:82/buzzer/off"
    print(f"🌐 อุปกรณ์: device_id={DEVICE_ID} driver_id={DRIVER_ID} trip_id={TRIP_ID} ip={DEVICE_IP}")
    print(f"   STREAM_URL     = {STREAM_URL}")
    print(f"   BUZZER_ON_URL  = {BUZZER_ON_URL}")
    print(f"   BUZZER_OFF_URL = {BUZZER_OFF_URL}")
    if not TRIP_ID:
        print("⚠️ driver คนนี้ยังไม่มี trip ที่กำลังทำอยู่ -> alert จะยังส่งได้ แต่ trip_id จะเป็นค่าว่าง")


# ==================== ดึงข้อมูลอุปกรณ์ตอนเริ่มโปรแกรม (รอจนกว่าจะได้) ====================
print(f"🔄 กำลังขอข้อมูลอุปกรณ์จาก Laravel (serial={DEVICE_SERIAL_NUMBER}): {DEVICE_LOOKUP_ENDPOINT}")
_info = None
for attempt in range(1, DEVICE_IP_FETCH_MAX_RETRIES + 1):
    _info = fetch_device_info()
    if _info:
        break
    print(f"⏳ ยังไม่ได้ข้อมูล ลองใหม่ครั้งที่ {attempt}/{DEVICE_IP_FETCH_MAX_RETRIES}...")
    time.sleep(DEVICE_IP_FETCH_RETRY_DELAY)

if not _info:
    print("\n❌ [FATAL ERROR] ไม่สามารถดึงข้อมูลอุปกรณ์จาก Laravel ได้เลย")
    print("   เช็คว่า: 1) Laravel รันอยู่ไหม  2) serial_number นี้ลงทะเบียนในระบบแล้วหรือยัง  3) ESP32 ต่อ WiFi และ register IP สำเร็จหรือยัง")
    exit()

refresh_device_urls(_info)
last_ip_check_time = time.time()

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
        "snapshot_url": f"{LARAVEL_BASE_URL}/storage/snapshots/default.jpg",
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
    # ---- เช็คว่า IP ของ ESP32 เปลี่ยนไปไหม (เผื่อ ESP32 รีสตาร์ท/สลับ WiFi ระหว่างรัน) ----
    now = time.time()
    if now - last_ip_check_time >= DEVICE_IP_REFRESH_SECONDS:
        last_ip_check_time = now
        new_info = fetch_device_info()
        if new_info:
            ip_changed = new_info.get("ip_address") != DEVICE_IP
            trip_changed = new_info.get("trip_id") != TRIP_ID

            if ip_changed:
                print(f"🔄 [IP CHANGED] ตรวจพบ IP อุปกรณ์เปลี่ยนจาก {DEVICE_IP} -> {new_info.get('ip_address')}")
            if trip_changed:
                print(f"🔄 [TRIP CHANGED] trip_id เปลี่ยนจาก {TRIP_ID} -> {new_info.get('trip_id')}")

            if ip_changed or trip_changed:
                refresh_device_urls(new_info)

            if ip_changed:
                print("🔄 กำลัง Reconnect สตรีมไปที่ IP ใหม่...")
                cap.release()
                cap = cv2.VideoCapture(STREAM_URL)
                cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
                fail_count = 0

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
        f"yaw: {yaw_deg:.1f} deg | IP: {DEVICE_IP}",
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
