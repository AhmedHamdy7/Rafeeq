# RAFEEQ Product Operating Manual

# Chapter 12 — Admin Portal, Operations & Platform Management

## Goal

The Admin Portal is the operational backbone of RAFEEQ.

It allows the operations team to keep the platform safe, reliable, and scalable without directly modifying production data outside controlled workflows.

---

# Story

A new driver submits verification documents.

The Operations Team logs into the Admin Portal.

The dashboard immediately shows:

- Pending driver approvals
- Active trips
- Open incidents
- Payment disputes
- Recent registrations
- Platform health

An administrator opens Ahmed's verification request, reviews the submitted documents, approves the account, and Ahmed is notified instantly.

---

# Admin Roles

### Super Admin
- Full platform access
- Manage roles and permissions
- View audit logs

### Operations Admin
- Approve drivers
- Manage commutes
- Handle incidents

### Support Agent
- View users
- Resolve tickets
- Issue approved refunds

### Finance Admin
- Monitor payments
- Approve payouts
- Review failed transactions

---

# User Management

Admins can:

- Search users
- View profile history
- Suspend accounts
- Reactivate accounts
- Verify identity status
- Review trust score
- View booking history

Sensitive actions require confirmation and are audited.

---

# Driver Verification Queue

Workflow:

Submitted
↓
Under Review
↓
Approved

or

Rejected

or

Needs More Information

---

# Incident Moderation

Every report includes:

- Reporter
- Reported user
- Booking
- Evidence
- Timeline

Admins may:

- Warn user
- Suspend account
- Permanently ban
- Close report

---

# Payment Operations

Finance admins can:

- Review refunds
- Review payouts
- Detect duplicate transactions
- Investigate failed payments
- Export financial reports

---

# Fraud Detection

Indicators include:

- Multiple fake accounts
- Excessive cancellations
- Repeated no-shows
- Rating manipulation
- Suspicious payment activity

High-risk accounts are flagged for manual review.

---

# Audit Logs

Every sensitive action is recorded:

- Login
- Approval
- Rejection
- Refund
- Suspension
- Permission changes

Audit logs are immutable.

---

# Database

## admin_users

- id
- name
- email
- role
- status

## admin_actions

- id
- admin_id
- action
- entity_type
- entity_id
- created_at

## support_tickets

- id
- user_id
- category
- priority
- status
- assigned_admin
- created_at

---

# APIs

GET /admin/dashboard

GET /admin/users

PATCH /admin/users/{id}

GET /admin/incidents

PATCH /admin/incidents/{id}

GET /admin/payouts

POST /admin/refunds

GET /admin/audit-logs

---

# Security

- Role-based access control
- Multi-factor authentication
- Session timeout
- IP monitoring
- Immutable audit logs
- Permission checks on every request

---

# Flutter / Web Structure

features/
  admin/
    dashboard/
    users/
    incidents/
    finance/
    support/

State Managers

- DashboardCubit
- UserManagementCubit
- IncidentCubit
- FinanceCubit
- SupportCubit

---

# QA

✓ Driver approval
✓ Account suspension
✓ Refund approval
✓ Incident resolution
✓ Audit log generation
✓ Permission enforcement
✓ Fraud flag review

---

# Chapter Result

RAFEEQ now has a complete operational platform allowing administrators, support agents, finance teams, and operations staff to manage users, payments, safety, and platform health securely while maintaining a full audit trail.
