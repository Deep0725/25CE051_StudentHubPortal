# Student Hub Portal

## About the Project

Student Hub Portal is a web-based student management system developed as a web development project. It provides a common platform for students to access their dashboard, profile, attendance, events, and other useful information. Administrators can manage student records through admin-only features.

## Features

- Student registration and login
- Secure password storage using password hashing
- Session-based authentication and logout
- Role-based access for students and administrators
- Student dashboard and profile
- Attendance page
- Events and notices
- Contact, FAQ, and feedback pages
- Admin dashboard
- Student management with add, view, edit, and delete operations
- Search and filter student records
- MySQL database integration
- Form validation

## Technologies Used

- **HTML** — Web page structure
- **CSS** — Styling and responsive layout
- **JavaScript** — Client-side validation and interactivity
- **PHP** — Server-side processing and authentication
- **MySQL** — Database management
- **WAMP Server** — Local development environment

## Requirements

Before running the project, install:

- WAMP Server
- A web browser
- Visual Studio Code or another code editor

## Installation and Setup

### 1. Copy the Project

Place the project folder inside the WAMP `www` directory.

Example:

C:\wamp64\www\StudentHub


Use your actual project folder name if it is different.

### 2. Start WAMP Server

Start WAMP and make sure the Apache and MySQL services are running.

### 3. Create the Database

1. Open your browser.
2. Visit `http://localhost/phpmyadmin`.
3. Open the **Import** tab.
4. Select the `studenthub.sql` file from your project folder.
5. Import the file to create the required database structure and tables.

### 4. Configure the Database Connection

Open `db.php` and check that the database host, database name, username, and password match your local MySQL configuration.

The database used by the project is named `studenthub`.

### 5. Run the Project

Open your browser and visit:

http://localhost/StudentHub/


Replace `StudentHub` with the actual name of your project folder. If necessary, open the appropriate starting PHP page directly.

**Important:** Run the project through `localhost`, not by opening PHP files directly from File Explorer.

## User Roles

### Student

Students can access the student-facing pages and features available to their account, such as the dashboard, profile, and attendance page.

### Administrator

Administrators can access authorized administrative features, including student-record management.

Admin-only access requires an account with the appropriate role in the database.

## Database

The project uses a MySQL database named `studenthub`.

The `studenthub.sql` file contains the database setup script. Import it into phpMyAdmin before using features that require database access.

## Security

- Passwords are stored using password hashing.
- Server-side validation helps verify submitted data.
- Prepared statements help protect database queries against SQL injection.
- Sessions are used to manage authenticated users.
- Role checks restrict access to administrative pages.

Keep database credentials and administrator login details private.

## Troubleshooting

**Database connection error**
- Check that MySQL is running.
- Verify the credentials and database name in `db.php`.

**Page not found**
- Confirm that the project is inside WAMP's `www` directory.
- Check the folder name in the browser URL.

**Database or table not found**
- Import `studenthub.sql` using phpMyAdmin.
- Confirm that the database name matches the configuration in `db.php`.

**Admin page access denied**
- Log in with an account that has the administrator role.
- Verify the account's role in the database.

**PHP code does not run**
- Start WAMP and open the project through `http://localhost/...`.
- Do not open PHP files directly from File Explorer.

## Project Objective

The objective of Student Hub Portal is to demonstrate how HTML, CSS, JavaScript, PHP, and MySQL can be combined to build a simple student portal with authentication, role-based access, and student-record management.
