# SIBA Public School Website & Management System

A complete, modern, and responsive school website built with **PHP (Custom)**, **MySQL**, and **jQuery**. This project includes a public-facing informational site, a secure **Parent Portal** for admissions and fees, and a robust **Admin Panel** for school management.

## 🚀 Features

### 🌐 Public Website
- **Home**: Hero section, school highlights, statistics, and call-to-action.
- **About Us**: Vision, Mission, History, and Leadership team.
- **Academics**: Curriculum details (CBSE), departments, and co-curricular activities.
- **Admissions**: 4-step process guide, eligibility, fee structure, and interactive FAQ.
- **Campus Life**: Infrastructure, Hostel facilities, and student life gallery.
- **Events & News**: Dynamic event calendar and latest school news.
- **Contact**: School contact info and enquiry form.

### 🛡️ Parent Portal
- **OTP Registration**: Secure registration using phone number with simulated OTP verification.
- **Online Admission**: Comprehensive application form with document upload (Birth Certificate, Aadhaar, Photo).
- **Dashboard**: Real-time tracking of application status (Submitted → Under Review → Admitted/Rejected).
- **Fee Payment**: Simulated fee payment interface for admitted students with payment history.
- **Sidebar Navigation**: Adaptive navigation based on student admission status.

### ⚙️ Admin Panel
- **Dashboard**: High-level statistics of applications, students, and fees collected.
- **Application Management**: Review applications, view uploaded documents, and update admission status.
- **Fee Reports**: Filterable payment records by month and year.
- **Staff Management**: Add, deactivate, or delete school staff members.
- **Notifications**: Broadcast messages to specific parent groups.
- **System Settings**: Configure school info, fees, and admission status.

## 🛠️ Technology Stack
- **Backend**: PHP 8.x (No framework)
- **Database**: MySQL
- **Frontend**: HTML5, Vanilla CSS, JavaScript (jQuery 3.7)
- **Icons**: FontAwesome 6.4
- **Fonts**: Google Fonts (Inter)

## 📦 Installation & Setup

1. **Clone the repository:**
   ```bash
   git clone https://github.com/dcoder777/SIBA_Public_School.git
   ```

2. **Database Setup:**
   - Import the `sql/schema.sql` file into your MySQL database.
   - Default Admin Credentials: `admin` / `password`
   - Default Parent Credentials: `1234567890` / `password`

3. **Configuration:**
   - Update `includes/config.php` with your database credentials and local `SITE_URL`.

4. **Upload Permissions:**
   - Ensure `uploads/docs/` and `uploads/photos/` directories are writable by the server.

---
*Developed for SIBA Public School - Nurturing Brilliance, Shaping Futures.*
