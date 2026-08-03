# RAFEEQ Product Operating Manual

# Chapter 7 — Payments & Wallet

## Goal

After a booking is confirmed, RAFEEQ must safely handle money between passengers and drivers while keeping a complete financial history.

---

# Story

Sara books Ahmed's commute.

Before the trip starts, RAFEEQ shows:

- Trip price
- Payment method
- Cancellation policy
- Total amount

Sara chooses to pay using her preferred payment method.

RAFEEQ authorises the payment and creates a financial transaction.

The money is **not immediately considered driver earnings**.

It becomes available only after the trip is completed successfully.

---

# Payment Flow

Passenger
↓
Choose payment method
↓
Payment gateway
↓
Payment authorised
↓
Booking marked as Paid
↓
Trip completed
↓
Driver earnings updated
↓
Wallet balance available for withdrawal

---

# Wallets

Every passenger has:

- Payment history
- Refund history

Every driver has:

- Available balance
- Pending balance
- Withdrawn balance
- Earnings history

---

# Database

## wallets

- id
- user_id
- available_balance
- pending_balance
- currency
- created_at

One wallet per user.

---

## wallet_transactions

- id
- wallet_id
- booking_id
- type
- amount
- status
- created_at

Types

- payment
- earning
- refund
- withdrawal
- adjustment

Ledger records are immutable.

---

## payment_methods

- id
- user_id
- provider
- token
- last4
- is_default

Never store raw card numbers.

---

## payouts

- id
- driver_id
- amount
- status
- requested_at
- completed_at

---

# APIs

POST /v1/payments/pay

POST /v1/payments/refund

GET /v1/wallet

GET /v1/wallet/transactions

POST /v1/payouts/request

---

# Cancellation Rules

Passenger cancels before deadline

→ Refund according to policy.

Passenger cancels after deadline

→ Partial or no refund.

Driver cancels

→ Full refund.

System cancellation

→ Automatic refund.

---

# Security

- PCI-compliant payment provider.
- Tokenised cards only.
- Signed webhook validation.
- Idempotency keys for payment requests.
- Immutable transaction ledger.
- Audit every refund.

---

# Flutter

features/
  wallet/
  payments/
  payouts/

Cubits

- PaymentCubit
- WalletCubit
- TransactionHistoryCubit
- PayoutCubit

---

# QA

- Successful payment
- Failed payment
- Duplicate payment request
- Refund issued
- Driver payout
- Wallet balance updated
- Webhook retry handled

---

# Chapter Result

Bookings now have financial records, drivers accumulate earnings after completed trips, passengers can receive refunds, and every money movement is permanently recorded in the wallet ledger.
