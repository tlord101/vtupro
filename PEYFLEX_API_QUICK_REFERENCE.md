# Peyflex API - Quick Reference

## Base Configuration

```php
// Using the PeyflexHelper
$peyflex = new PeyflexHelper();
```

## Airtime Purchase

### Step 1: Get Available Networks

**Endpoint:** `GET /api/v1/user/peyflex/airtime/networks`

**Response:**
```json
{
    "success": true,
    "message": "Airtime networks fetched successfully",
    "data": [
        {
            "id": "mtn_nigeria",
            "name": "MTN Nigeria",
            "country_code": "NG"
        },
        {
            "id": "glo_nigeria",
            "name": "Glo Nigeria",
            "country_code": "NG"
        },
        ...
    ]
}
```

### Step 2: Validate Network & Phone

**Endpoint:** `POST /api/v1/user/peyflex/airtime/check-network`

**Request:**
```json
{
    "network": "mtn_nigeria",
    "mobile_number": "08000000000"
}
```

**Response:**
```json
{
    "success": true,
    "message": "Network validated successfully",
    "data": {
        "network": "mtn_nigeria",
        "mobile_number": "08000000000",
        "valid": true
    }
}
```

### Step 3: Purchase Airtime

**Endpoint:** `POST /api/v1/user/peyflex/airtime/purchase`

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request:**
```json
{
    "network": "mtn_nigeria",
    "mobile_number": "08000000000",
    "amount": 500
}
```

**Success Response:**
```json
{
    "success": true,
    "message": "Airtime purchase successful",
    "data": {
        "trx_id": "AT1735338451234",
        "transaction_id": "peyflex_txn_123456",
        "status": "successful"
    }
}
```

**Error Response:**
```json
{
    "success": false,
    "errors": [
        "Insufficient balance"
    ]
}
```

---

## Data Purchase

### Step 1: Get Available Networks

**Endpoint:** `GET /api/v1/user/peyflex/data/networks`

**Response:**
```json
{
    "success": true,
    "message": "Data networks fetched successfully",
    "data": [
        {
            "id": "mtn_sme_data",
            "name": "MTN SME Data",
            "country_code": "NG"
        },
        {
            "id": "mtn_gifting_data",
            "name": "MTN Gifting Data",
            "country_code": "NG"
        },
        ...
    ]
}
```

### Step 2: Get Available Plans

**Endpoint:** `POST /api/v1/user/peyflex/data/plans`

**Request:**
```json
{
    "network": "mtn_sme_data"
}
```

**Response:**
```json
{
    "success": true,
    "message": "Data plans fetched successfully",
    "data": [
        {
            "id": "M100MBS",
            "name": "100MB",
            "price": 5.00,
            "validity": "1 day"
        },
        {
            "id": "M500MBS",
            "name": "500MB",
            "price": 15.00,
            "validity": "7 days"
        },
        {
            "id": "M1GB",
            "name": "1GB",
            "price": 25.00,
            "validity": "30 days"
        },
        ...
    ]
}
```

### Step 3: Calculate Charges

**Endpoint:** `POST /api/v1/user/peyflex/data/calculate-charges`

**Request:**
```json
{
    "network": "mtn_sme_data",
    "plan_code": "M500MBS",
    "amount": 15
}
```

**Response:**
```json
{
    "success": true,
    "message": "Charges calculated successfully",
    "data": {
        "amount": "15.00",
        "percent_charge": "0.75",
        "fixed_charge": "0.00",
        "total_charge": "0.75",
        "payable": "15.75"
    }
}
```

### Step 4: Purchase Data

**Endpoint:** `POST /api/v1/user/peyflex/data/purchase`

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request:**
```json
{
    "network": "mtn_sme_data",
    "mobile_number": "08000000000",
    "plan_code": "M500MBS",
    "amount": 15
}
```

**Success Response:**
```json
{
    "success": true,
    "message": "Data purchase successful",
    "data": {
        "trx_id": "DP1735338451234",
        "transaction_id": "peyflex_txn_123456",
        "status": "successful"
    }
}
```

**Error Response:**
```json
{
    "success": false,
    "errors": [
        "Invalid plan code"
    ]
}
```

---

## Available Networks

### Airtime Networks
- `mtn_nigeria` - MTN Nigeria
- `glo_nigeria` - Glo Nigeria
- `airtel_nigeria` - Airtel Nigeria
- `9mobile_nigeria` - 9Mobile Nigeria

### Data Networks
- `mtn_sme_data` - MTN SME Data
- `mtn_gifting_data` - MTN Gifting Data
- `glo_data` - Glo Data
- `airtel_data` - Airtel Data
- `9mobile_data` - 9Mobile Data

---

## Data Plans Examples

### MTN SME Data (`mtn_sme_data`)
- `M100MBS` - 100MB (₦5.00, 1 day)
- `M500MBS` - 500MB (₦15.00, 7 days)
- `M1GB` - 1GB (₦25.00, 30 days)
- `M5GB` - 5GB (₦100.00, 30 days)

### MTN Gifting Data (`mtn_gifting_data`)
- `G1GB` - 1GB (₦30.00, 30 days)
- `G5GB` - 5GB (₦120.00, 30 days)
- `G10GB` - 10GB (₦200.00, 30 days)

### Glo Data (`glo_data`)
- `G500MB` - 500MB (₦20.00, 7 days)
- `G1GB` - 1GB (₦35.00, 30 days)
- `G5GB` - 5GB (₦130.00, 30 days)

### Airtel Data (`airtel_data`)
- `A500MB` - 500MB (₦18.00, 7 days)
- `A1GB` - 1GB (₦32.00, 30 days)
- `A5GB` - 5GB (₦110.00, 30 days)

---

## Helper Methods (PHP)

### Using PeyflexHelper in your code

```php
use App\Http\Helpers\PeyflexHelper;

// Initialize
$peyflex = new PeyflexHelper();

// Get networks
$networks = $peyflex->getAirtimeNetworks();
$data_networks = $peyflex->getDataNetworks();

// Get plans
$plans = $peyflex->getDataPlans('mtn_sme_data');

// Make purchase
$airtime_result = $peyflex->airtimeTopup([
    'network' => 'mtn_nigeria',
    'mobile_number' => '08000000000',
    'amount' => 500,
]);

$data_result = $peyflex->dataPurchase([
    'network' => 'mtn_sme_data',
    'mobile_number' => '08000000000',
    'plan_code' => 'M500MBS',
]);

// Check response
if($airtime_result['status']) {
    $transaction_id = $airtime_result['transaction_id'];
    // Process success
} else {
    $error = $airtime_result['message'];
    // Process error
}
```

---

## Status Codes

| Code | Meaning | Action |
|------|---------|--------|
| 200 | OK | Request successful |
| 400 | Bad Request | Check request format/parameters |
| 401 | Unauthorized | Verify authentication token |
| 402 | Payment Required | Insufficient balance |
| 403 | Forbidden | KYC verification required |
| 500 | Server Error | Contact support |

---

## Error Messages

| Error | Cause | Solution |
|-------|-------|----------|
| Insufficient balance | Wallet balance < payable | Add funds to wallet |
| Invalid mobile number length | Phone number incorrect | Verify phone number format |
| Invalid plan code | Plan doesn't exist | Select valid plan from list |
| User wallet not found | Wallet missing | Contact support |
| Service temporarily unavailable | API/Settings issue | Retry later or contact support |

---

## Transaction Tracking

### Get Transaction Status

Use the transaction ID returned to track status:

**Database Query:**
```sql
SELECT * FROM transactions WHERE trx_id = 'AT1735338451234';
```

**Response Fields:**
- `trx_id` - Transaction ID
- `status` - SUCCESSFUL, PROCESSING, FAILED
- `created_at` - Transaction timestamp
- `details` - JSON with API response

---

## Integration Checklist

- [ ] Configure Peyflex credentials in admin panel
- [ ] Set mobile topup charges (percent + fixed)
- [ ] Set data bundle charges (percent + fixed)
- [ ] Test with sandbox credentials
- [ ] Verify transaction recording
- [ ] Configure email notifications
- [ ] Test error scenarios
- [ ] Switch to production credentials
- [ ] Monitor transactions
- [ ] Setup alerts for failed transactions

---

## Support Resources

- **Documentation:** See `PEYFLEX_PRODUCTION_GUIDE.md`
- **API Docs:** See `doc.txt`
- **Peyflex Website:** https://peyflex.com
- **Support Email:** support@peyflex.com
