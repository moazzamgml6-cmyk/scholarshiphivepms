# Processing Team Portal — Isolated Training & Practice Package

Ye package **Training aur Practice** ke liye banaya gaya hai. Iska database (`database/training_portal.sqlite`) asal Main Production Database se **100% alag (isolated)** aur sanitized hai. Ismein kiye gaye kisi bhi INSERT, UPDATE, DELETE ya file upload ka asal database par koi asar nahi hota.

---

## 1. Folder Structure

```text
training-portal-package/
├── database/
│   ├── training_portal.sqlite               # Active Training Database (SQLite 3)
│   ├── training_portal_reset_backup.sqlite  # Clean snapshot to reset training data anytime
│   └── training_portal_dump.sql             # Standard SQL schema + seed dump
├── portal/                                  # Complete PHP web application (configured for Training DB)
│   ├── app/
│   │   ├── config.php                       # Training app settings
│   │   └── config.local.php                 # Connects ONLY to ../database/training_portal.sqlite
│   └── uploads/documents/samples/           # Safe sample PDF files for all 12 document types
├── start-training-portal.bat                # One-click launcher (runs on http://127.0.0.1:8080)
├── reset-training-database.bat              # One-click script to reset database after practice
└── README-TRAINING-SETUP.md                 # Setup & usage guide
```

---

## 2. Doosre System Par Run Karne Ka Tareeqa (How to Run)

### Option A: One-Click Windows Launcher (Recommended)
1. Poore `training-portal-package` folder (ya `.zip` file) ko doosre computer par copy/extract karein.
2. Ensure karein ke system par **PHP 8.0+** installed hai (aur `pdo_sqlite` extension enabled hai).
3. `start-training-portal.bat` par double-click karein.
4. Browser mein ye link kholein:
   - **Home / Portal Selector:** `http://127.0.0.1:8080`
   - **Admin Portal:** `http://127.0.0.1:8080/admin`
   - **Officer Portal:** `http://127.0.0.1:8080/officer`
   - **Student Portal:** `http://127.0.0.1:8080/student`

### Option B: Command Line / Terminal Se Run Karna
Terminal ya PowerShell mein `training-portal-package` folder ke andar ye command chalayein:
```powershell
php -S 127.0.0.1:8080 -t portal portal/router.php
```

---

## 3. Training Login Credentials

Tamam training accounts ka password **`Password123!`** rakha gaya hai (production passwords hata diye gaye hain):

| Role | Department | Username | Password | Assigned Records |
|---|---|---|---|---|
| **Admin** | Full Access | `admin` | `Password123!` | All 250 Students & All 5 Officers |
| **Officer** | Front End Team | `nimra` | `Password123!` | 148 Students |
| **Officer** | Front End Team | `mujassam` | `Password123!` | 99 Students |
| **Officer** | Back End Team | `minahil` | `Password123!` | 84 Students |
| **Officer** | Back End Team | `sehrish` | `Password123!` | 84 Students |
| **Officer** | Back End Team | `farwa` | `Password123!` | 82 Students |
| **Student** | Student Portal | `sajjad.khan` *(or any student)* | `Password123!` | Individual Student View |

---

## 4. Training Data Reset Karne Ka Tareeqa

Agar practice ke dauran trainee ne records delete ya modify kar diye hon aur database ko dobara shuru wali clean halat mein wapas lana ho, to:
- `reset-training-database.bat` par double-click karein, **YA**
- `database/training_portal_reset_backup.sqlite` ko copy karke `database/training_portal.sqlite` ke naam se replace kar dein.

---

## 5. Privacy & Sanitization Notes

- Asal Admin password aur tamam user passwords ko training password (`Password123!`) se replace kar diya gaya hai.
- Students ke personal Google Drive links, private documents (Passports, CNIC, Bank Statements), emails, aur UniverseItaly passwords ko safe training placeholders aur local Sample PDFs (`uploads/documents/samples/`) se replace kiya gaya hai.
- Tamam 250 students ke stages, officer assignments (Front End + Back End), remarks, aur document verification statuses (`Received`, `Verified`, `Pending`) 100% preserve kiye gaye hain taake training bilkul real workflow ke mutabiq ho.
