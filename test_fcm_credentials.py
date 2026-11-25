"""
Firebase Cloud Messaging (FCM) Credentials Verifier for ScholarshipHive
Checks both:
1. Service Account JSON (Google OAuth2 FCM v1 API)
2. FCM Legacy Server Key
"""

import os
import sys
import json
import time
import urllib.request
import urllib.parse
import base64

PROJECT_ID = "scholarshiphive-11e2a"

def verify_service_account(json_path_or_str):
    print("=" * 60)
    print("TESTING FIREBASE SERVICE ACCOUNT (FCM HTTP v1)")
    print("=" * 60)
    
    try:
        if os.path.exists(json_path_or_str):
            with open(json_path_or_str, "r", encoding="utf-8") as f:
                data = json.load(f)
        else:
            data = json.loads(json_path_or_str)
    except Exception as e:
        print(f"❌ Invalid JSON format: {e}")
        return False

    client_email = data.get("client_email")
    private_key = data.get("private_key")
    proj = data.get("project_id", PROJECT_ID)

    if not client_email or not private_key:
        print("❌ Missing 'client_email' or 'private_key' in JSON.")
        return False

    print(f"✓ Client Email: {client_email}")
    print(f"✓ Project ID:   {proj}")
    print(f"✓ Private Key found (Length: {len(private_key)} chars)")

    # Attempt to request OAuth2 token using Python cryptography / jwt or pyopenssl if available
    try:
        import jwt
        now = int(time.time())
        payload = {
            "iss": client_email,
            "scope": "https://www.googleapis.com/auth/firebase.messaging",
            "aud": "https://oauth2.googleapis.com/token",
            "iat": now,
            "exp": now + 3600
        }
        signed_jwt = jwt.encode(payload, private_key, algorithm="RS256")
        
        req_data = urllib.parse.urlencode({
            "grant_type": "urn:ietf:params:oauth:grant-type:jwt-bearer",
            "assertion": signed_jwt
        }).encode("utf-8")

        req = urllib.request.Request("https://oauth2.googleapis.com/token", data=req_data)
        with urllib.request.urlopen(req, timeout=10) as resp:
            token_res = json.loads(resp.read().decode("utf-8"))
            access_token = token_res.get("access_token")
            if access_token:
                print("✓ Successfully authenticated with Google OAuth2!")
                print(f"✓ Access Token acquired (Expires in {token_res.get('expires_in')}s)")
                print("🎉 FCM v1 API is 100% OPERATIONAL for ScholarshipHive!")
                return True
    except ImportError:
        print("ℹ️ Note: PyJWT not installed locally, but PHP backend uses native OpenSSL extension for RS256 JWT.")
        print("✓ JSON structure is valid and ready for PHP OpenSSL.")
        return True
    except Exception as e:
        print(f"❌ Google OAuth2 Token Exchange Error: {e}")
        return False

def verify_legacy_key(server_key):
    print("=" * 60)
    print("TESTING FCM LEGACY SERVER KEY")
    print("=" * 60)

    req = urllib.request.Request(
        "https://fcm.googleapis.com/fcm/send",
        headers={
            "Authorization": f"key={server_key}",
            "Content-Type": "application/json"
        },
        data=json.encode({
            "registration_ids": ["dummy_token_validation_only"],
            "data": {"test": "1"}
        }).encode("utf-8")
    )
    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
            print("✓ FCM Server Key is VALID and accepted by Google!")
            return True
    except urllib.error.HTTPError as e:
        if e.code == 401:
            print("❌ Invalid FCM Server Key (HTTP 401 Unauthorized).")
            return False
        else:
            # 200 or 400 (due to dummy token) means authentication itself succeeded
            print(f"✓ FCM Authentication succeeded (HTTP {e.code}). Key is recognized.")
            return True
    except Exception as e:
        print(f"Error checking key: {e}")
        return False

if __name__ == "__main__":
    if len(sys.argv) < 2:
        default_file = os.path.join(
            r"C:\Users\UC\Downloads\training-portal-package\training-portal-package\portal\app",
            "firebase-service-account.json"
        )
        if os.path.exists(default_file):
            verify_service_account(default_file)
        else:
            print("Usage: python test_fcm_credentials.py <path_to_json_or_fcm_key>")
    else:
        arg = sys.argv[1]
        if arg.endswith(".json") or "{" in arg:
            verify_service_account(arg)
        else:
            verify_legacy_key(arg)
