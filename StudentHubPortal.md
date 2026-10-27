# Student Hub Portal

## Introduction

Student Hub Portal is a web-based student management portal designed to provide students with easy access to important academic information and student services from one place.

The portal provides a simple and user-friendly interface for accessing student-related information.

## Objectives

The main objectives of Student Hub Portal are:

- Provide a centralized platform for students
- Provide easy access to academic information
- Allow students to view their profile
- Allow students to check attendance
- Provide a password recovery system
- Provide frequently asked questions
- Provide technical support information

## Features

### Home

The Home page introduces the Student Hub Portal and provides options for students to register or log in.

### About

The About page explains the purpose of the Student Hub Portal and its major features.

### Student Registration

New students can register by providing:

- Full Name
- Email Address
- Mobile Number
- Password
- Confirm Password
- Course
- Year of Study
- Gender
- Terms and Conditions Acceptance

### Student Login

Registered students can log in using their username and password.

### Forgot Password

Students who forget their password can use the password recovery process.

The process is:

Forgot Password → Verify OTP → Reset Password → Login

### Dashboard

After login, students are redirected to the dashboard.

The dashboard provides access to:

- Profile
- Attendance
- Logout

### Profile

The Profile page displays:

- Personal Information
- Academic Information

### Events

The Events page displays upcoming campus activities and technical sessions loaded dynamically from `events.json` with category filtering, date sorting, keyword search, and pagination.

### Notices

The Notices page displays college notices and announcements loaded dynamically from `notices.json` with category filtering, date/title sorting, search, and pagination.

### Attendance

Students can view their attendance for different subjects.

### Contact

The Contact page provides Student Hub support information such as:

- Support Email
- Phone Number
- Office Hours
- IT Help Desk Email

### FAQ

The FAQ page provides answers to common questions loaded dynamically from `faq.json` with real-time keyword search and alphabetical sorting:

- Login
- Password recovery
- Dashboard
- Attendance
- Study materials
- Results
- Browser support
- Technical problems

## Website Structure

Student Hub Portal
│
├── Home
├── About
├── Register
├── Login
│   ├── Forgot Password
│   │   ├── Verify OTP
│   │   └── Reset Password
│   └── Dashboard
│       ├── Profile
│       ├── Attendance
│       └── Logout
│
├── Events
├── Notices
├── Contact
└── FAQ
