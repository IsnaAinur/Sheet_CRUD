# Google Sheets CRUD

Aplikasi CRUD (Create, Read, Update, Delete)  menggunakan **Native PHP** dan **Google Sheets API** sebagai database penyimpanan data. Project ini menggunakan **Google Service Account** untuk mengakses spreadsheet.

## Features

* Menampilkan data dari Google Sheets
* Menambahkan data baru
* Mengubah data yang sudah ada
* Menghapus data
* Dashboard
* Integrasi Google Sheets API menggunakan Service Account

## Technologies Used

* PHP Native
* Google Sheets API
* Google Service Account
* HTML
* CSS
* Composer

## Project Structure

```text
webservice/
│
├── index.php
├── style.css
├── config.php
├── google-service-account.json
├── google-service-account.example.json
└── README.md
```

## Data Structure

Spreadsheet menggunakan 3 kolom utama:

| Name     | Email                                   | Status |
| -------- | --------------------------------------- | ------ |
| example | [example@gmail.com](mailto:john@gmail.com) | Active |

## Requirements

* PHP 8.0+
* Composer
* Google Cloud Project
* Google Sheets API Enabled
* Google Service Account
* XAMPP/Laragon

## Installation

### 1. Clone Repository

```bash
git clone https://github.com/IsnaAinur/Sheet_CRUD.git
```

### 2. Masuk ke Folder Project

```bash
cd Sheet_CRUD
```

### 3. Install Dependency

```bash
composer install
```

### 4. Buat Google Service Account

1. Buka Google Cloud Console.
2. Buat Project baru.
3. Enable Google Sheets API.
4. Buat Service Account.
5. Generate JSON Key.
6. Simpan file JSON ke folder project.

Contoh:

```text
google-service-account.json
```

### 5. Share Spreadsheet

Bagikan spreadsheet ke email Service Account dengan akses:

```text
Editor
```

Contoh email:

```text
sheet-service@my-project.iam.gserviceaccount.com
```

### 6. Konfigurasi Project

Edit file `config.php`:

```php
define('SPREADSHEET_ID', 'YOUR_SPREADSHEET_ID');
define('GOOGLE_CREDENTIALS', 'google-service-account.json');
```

### 7. Jalankan Project

Jika menggunakan XAMPP:

```text
C:\xampp\htdocs\Sheet_CRUD
```

Kemudian buka browser:

```text
http://localhost/Sheet_CRUD
```

## Preview

![alt text](image.png)

## Learning Objectives

Project ini dibuat untuk mempelajari:

* Integrasi Google Sheets API
* Implementasi CRUD menggunakan PHP Native
* Penggunaan Service Account Google
* Pengelolaan data berbasis Spreadsheet
* Pembuatan dashboard sederhana
