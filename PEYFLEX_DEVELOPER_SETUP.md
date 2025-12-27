# Peyflex Integration - Developer Setup Guide

## Overview

This guide walks you through integrating the production Peyflex API for airtime and data purchases in your application.

## Prerequisites

- Laravel 9+ installed
- Database configured
- Admin panel access
- Peyflex API credentials

---

## Step 1: Configure Peyflex Credentials

### Via Admin Panel

1. Login to admin dashboard
2. Navigate to **Settings → Payment Gateways → Peyflex API**
3. Add new configuration:
   - **Provider:** Peyflex
   - **API Key:** `your_peyflex_api_key`
   - **Secret Key:** `your_peyflex_secret_key` (if required)
   - **Public Key:** `your_peyflex_public_key` (if required)
   - **Environment:** `production` or `sandbox`
   - **Base URL (Production):** `https://client.peyflex.com.ng`
   - **Base URL (Sandbox):** `https://sandbox.peyflex.com.ng`

4. Click **Save & Test Connection**

### Via .env File (Optional)

```env
PEYFLEX_API_KEY=your_api_key
PEYFLEX_SECRET_KEY=your_secret_key
PEYFLEX_PUBLIC_KEY=your_public_key
PEYFLEX_ENV=production
```

---

## Step 2: Configure Transaction Charges

### Airtime Charges

1. Go to **Settings → Transaction Settings → Mobile Topup**
2. Set:
   - **Percent Charge:** e.g., 5 (for 5%)
   - **Fixed Charge:** e.g., 10 (flat amount)
   - **Status:** Enable

Example: If amount = ₦500
- Percent charge: ₦500 × 5% = ₦25
- Fixed charge: ₦10
- Total charge: ₦35
- User pays: ₦535

### Data Bundle Charges

1. Go to **Settings → Transaction Settings → Data Bundle**
2. Set same charge structure
3. Enable service

---

## Step 3: Enable KYC Requirement

Ensure KYC verification is required for purchases:

1. **Settings → User Settings → KYC Requirements**
2. Enable for both:
   - Airtime purchases
   - Data purchases

---

## Step 4: API Integration Examples

### Frontend Integration (JavaScript/Vue/React)

```javascript
// Get Airtime Networks
async function getAirtimeNetworks() {
  const response = await fetch('/api/v1/user/peyflex/airtime/networks', {
    headers: {
      'Authorization': `Bearer ${authToken}`,
      'Content-Type': 'application/json'
    }
  });
  return response.json();
}

// Purchase Airtime
async function purchaseAirtime(network, phone, amount) {
  const response = await fetch('/api/v1/user/peyflex/airtime/purchase', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${authToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      network,
      mobile_number: phone,
      amount
    })
  });
  return response.json();
}

// Get Data Networks
async function getDataNetworks() {
  const response = await fetch('/api/v1/user/peyflex/data/networks', {
    headers: {
      'Authorization': `Bearer ${authToken}`,
      'Content-Type': 'application/json'
    }
  });
  return response.json();
}

// Get Data Plans
async function getDataPlans(network) {
  const response = await fetch('/api/v1/user/peyflex/data/plans', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${authToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ network })
  });
  return response.json();
}

// Calculate Charges
async function calculateCharges(network, planCode, amount) {
  const response = await fetch('/api/v1/user/peyflex/data/calculate-charges', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${authToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      network,
      plan_code: planCode,
      amount
    })
  });
  return response.json();
}

// Purchase Data
async function purchaseData(network, phone, planCode, amount) {
  const response = await fetch('/api/v1/user/peyflex/data/purchase', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${authToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      network,
      mobile_number: phone,
      plan_code: planCode,
      amount
    })
  });
  return response.json();
}
```

### Backend Integration (Laravel/PHP)

```php
<?php

use App\Http\Helpers\PeyflexHelper;

// Initialize helper
$peyflex = new PeyflexHelper();

// Get networks
$airtime_networks = $peyflex->getAirtimeNetworks();
if($airtime_networks['status']) {
    $networks = $airtime_networks['data'];
}

// Get data networks
$data_networks = $peyflex->getDataNetworks();

// Get plans for network
$plans = $peyflex->getDataPlans('mtn_sme_data');

// Purchase airtime
$airtime_result = $peyflex->airtimeTopup([
    'network' => 'mtn_nigeria',
    'mobile_number' => '08000000000',
    'amount' => 500,
]);

if($airtime_result['status']) {
    $transaction_id = $airtime_result['transaction_id'];
    // Success - transaction recorded
} else {
    $error_msg = $airtime_result['message'];
    // Error handling
}

// Purchase data
$data_result = $peyflex->dataPurchase([
    'network' => 'mtn_sme_data',
    'mobile_number' => '08000000000',
    'plan_code' => 'M500MBS',
]);
```

---

## Step 5: Database Verification

### Check Transaction Recording

```sql
-- View recent transactions
SELECT * FROM transactions 
WHERE type IN ('PEYFLEX_AIRTIME', 'PEYFLEX_DATA') 
ORDER BY created_at DESC 
LIMIT 10;

-- Check specific transaction
SELECT * FROM transactions WHERE trx_id = 'AT1735338451234';

-- View transaction details (JSON)
SELECT trx_id, details FROM transactions 
WHERE type = 'PEYFLEX_AIRTIME' 
ORDER BY created_at DESC LIMIT 1;
```

### Verify Wallet Updates

```sql
-- Check wallet balance history
SELECT wallet_id, balance, updated_at 
FROM user_wallets 
WHERE user_id = ? 
ORDER BY updated_at DESC;
```

---

## Step 6: Email Configuration

### Enable Email Notifications

1. **Settings → Email → SMTP Configuration**
2. Configure:
   - SMTP Host
   - Port (usually 587)
   - Username/Password
   - From Address

3. **Settings → Notification Settings**
4. Enable:
   - ✓ Email Notifications
   - ✓ Transaction Notifications

### Test Email

```php
// Test in tinker
php artisan tinker
>>> Mail::raw('Test', function($message) { $message->to('test@example.com'); });
```

---

## Step 7: Testing Workflow

### Test Airtime Purchase

1. **Get Networks**
   ```bash
   curl -X GET "http://localhost:8000/api/v1/user/peyflex/airtime/networks" \
     -H "Authorization: Bearer TOKEN"
   ```

2. **Check Network**
   ```bash
   curl -X POST "http://localhost:8000/api/v1/user/peyflex/airtime/check-network" \
     -H "Authorization: Bearer TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"network":"mtn_nigeria","mobile_number":"08000000000"}'
   ```

3. **Purchase Airtime**
   ```bash
   curl -X POST "http://localhost:8000/api/v1/user/peyflex/airtime/purchase" \
     -H "Authorization: Bearer TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"network":"mtn_nigeria","mobile_number":"08000000000","amount":500}'
   ```

### Test Data Purchase

1. **Get Networks**
   ```bash
   curl -X GET "http://localhost:8000/api/v1/user/peyflex/data/networks" \
     -H "Authorization: Bearer TOKEN"
   ```

2. **Get Plans**
   ```bash
   curl -X POST "http://localhost:8000/api/v1/user/peyflex/data/plans" \
     -H "Authorization: Bearer TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"network":"mtn_sme_data"}'
   ```

3. **Calculate Charges**
   ```bash
   curl -X POST "http://localhost:8000/api/v1/user/peyflex/data/calculate-charges" \
     -H "Authorization: Bearer TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"network":"mtn_sme_data","plan_code":"M500MBS","amount":15}'
   ```

4. **Purchase Data**
   ```bash
   curl -X POST "http://localhost:8000/api/v1/user/peyflex/data/purchase" \
     -H "Authorization: Bearer TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"network":"mtn_sme_data","mobile_number":"08000000000","plan_code":"M500MBS","amount":15}'
   ```

---

## Step 8: Error Handling

### Common Errors & Solutions

| Error | Solution |
|-------|----------|
| `Peyflex Provider Not Found!` | Configure credentials in admin panel |
| `Insufficient balance` | User needs to add funds |
| `Invalid mobile number length` | Verify phone number format (10-15 digits) |
| `Service temporarily unavailable` | Check Peyflex API status |
| `User wallet not found` | Create wallet for user first |
| `Invalid plan code` | Select valid plan from network |

### Logging

Check logs for detailed error information:

```bash
# View Laravel logs
tail -f storage/logs/laravel.log

# Filter for Peyflex errors
grep -i "peyflex" storage/logs/laravel.log
```

---

## Step 9: Monitoring

### Transaction Monitoring

Create a simple dashboard query:

```php
// Get today's transactions
$today_transactions = Transaction::where('type', 'PEYFLEX_AIRTIME')
    ->whereDate('created_at', today())
    ->get();

// Success rate
$successful = $today_transactions->where('status', 'SUCCESSFUL')->count();
$total = $today_transactions->count();
$success_rate = ($successful / $total) * 100;

// Total volume
$total_amount = $today_transactions->sum('request_amount');
```

### Alert Conditions

Set up alerts for:
- Failed transaction rate > 10%
- API response time > 5 seconds
- Wallet balance errors
- Authentication failures

---

## Step 10: Production Checklist

Before going live:

- [ ] Peyflex credentials configured (production keys)
- [ ] Transaction charges configured
- [ ] Email notifications enabled and tested
- [ ] KYC requirements enabled
- [ ] Database backups scheduled
- [ ] Error logging configured
- [ ] API rate limiting configured
- [ ] SSL certificate installed
- [ ] CORS properly configured
- [ ] All endpoints tested
- [ ] Error scenarios tested
- [ ] Documentation reviewed
- [ ] Monitoring setup
- [ ] Support contact information available

---

## Troubleshooting

### Issue: "Failed to fetch networks"

**Cause:** API connectivity issue

**Solution:**
1. Check internet connection
2. Verify Peyflex API status
3. Check credentials in admin panel
4. Test in sandbox first

### Issue: Transactions not recording

**Cause:** Database transaction issue

**Solution:**
1. Check database connection
2. Verify transactions table exists
3. Check error logs for DB errors
4. Manually insert test record

### Issue: Wallet balance not updating

**Cause:** Transaction not committed

**Solution:**
1. Check for DB rollbacks in logs
2. Verify user wallet exists
3. Check for insufficient balance
4. Review transaction details

### Issue: Email not sending

**Cause:** SMTP configuration

**Solution:**
1. Verify SMTP credentials
2. Check firewall rules
3. Test with Laravel Dusk
4. Review mail logs

---

## Performance Optimization

### Caching

The system automatically caches:
- Networks: 24 hours
- Plans: 12 hours
- Access tokens: 1 hour

To clear cache:

```bash
# Clear all cache
php artisan cache:clear

# Clear specific cache
php artisan cache:forget "peyflex_airtime_networks_production_MOBILE-TOPUP"
```

### Database Indexing

Add indexes for performance:

```sql
ALTER TABLE transactions ADD INDEX idx_peyflex_type (type);
ALTER TABLE transactions ADD INDEX idx_peyflex_trx (trx_id);
ALTER TABLE transactions ADD INDEX idx_peyflex_user (user_id);
ALTER TABLE user_wallets ADD INDEX idx_user_balance (user_id, balance);
```

---

## Support & Resources

- **Quick Reference:** `PEYFLEX_API_QUICK_REFERENCE.md`
- **Full Guide:** `PEYFLEX_PRODUCTION_GUIDE.md`
- **Peyflex Docs:** `doc.txt`
- **Implementation Summary:** `PEYFLEX_IMPLEMENTATION_SUMMARY.md`

---

## Contact & Support

For issues or questions:
1. Check the documentation files
2. Review error logs
3. Test in sandbox environment
4. Contact Peyflex support team

---

## Version History

- **v1.0.0** (Current) - Production release with full Peyflex integration
