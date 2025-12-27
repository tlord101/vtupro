# Peyflex API - Production Implementation Guide

## Overview

This document outlines the production-ready implementation of the Peyflex API integration for both Airtime and Data purchases in the VTUPro application.

## Architecture

The implementation consists of:

1. **Helper Classes** (`app/Http/Helpers/PeyflexHelper.php`)
   - Core API communication layer
   - Token management and caching
   - Error handling and exception throwing

2. **API Controllers**
   - `PeyflexAirtimeController` - Airtime purchase endpoints
   - `PeyflexDataController` - Data bundle purchase endpoints

3. **API Routes** (`routes/api/v1/user.php`)
   - RESTful endpoints for airtime and data operations

## Production Implementation Details

### 1. Airtime Purchase

#### Endpoint: `POST /api/v1/user/peyflex/airtime/purchase`

**Request Body:**
```json
{
    "network": "mtn_nigeria",
    "mobile_number": "08000000000",
    "amount": 500
}
```

**Response (Success):**
```json
{
    "success": true,
    "message": "Airtime purchase successful",
    "data": {
        "trx_id": "AT0001234567",
        "transaction_id": "peyflex_transaction_id",
        "status": "successful"
    }
}
```

**Process Flow:**
1. Validate request parameters
2. Check user wallet balance
3. Calculate transaction charges (percent + fixed)
4. Call Peyflex API with network, phone, and amount
5. Handle response status (SUCCESSFUL, PENDING, FAILED)
6. Create transaction record in database
7. Update wallet balance
8. Send notification email

**Key Features:**
- Real token-based authentication with Peyflex API
- Production error handling (throws exceptions instead of returning mock data)
- Proper charge calculation based on admin settings
- Transaction logging and status tracking
- Email notifications for successful transactions

### 2. Data Purchase

#### Endpoint: `POST /api/v1/user/peyflex/data/purchase`

**Request Body:**
```json
{
    "network": "mtn_sme_data",
    "mobile_number": "08000000000",
    "plan_code": "M500MBS",
    "amount": 15
}
```

**Response (Success):**
```json
{
    "success": true,
    "message": "Data purchase successful",
    "data": {
        "trx_id": "DP0001234567",
        "transaction_id": "peyflex_transaction_id",
        "status": "successful"
    }
}
```

**Process Flow:**
1. Validate network, phone, plan code, and amount
2. Verify user wallet exists and has sufficient balance
3. Fetch transaction settings for data_bundle
4. Calculate charges (percent + fixed)
5. Call Peyflex API with purchase data
6. Process response status
7. Create transaction record
8. Update wallet balance
9. Send notification email

**Available Networks (Peyflex):**
- `mtn_sme_data` - MTN SME Data
- `mtn_gifting_data` - MTN Gifting Data
- `glo_data` - Glo Data
- `airtel_data` - Airtel Data
- `9mobile_data` - 9Mobile Data

### 3. Helper Methods

#### `getAirtimeNetworks()`
Fetches available airtime networks from Peyflex API.

**Response:**
```php
[
    'status' => true,
    'data' => [...network array...],
    'message' => 'Networks fetched successfully'
]
```

#### `getDataNetworks()`
Fetches available data networks from Peyflex API.

#### `getDataPlans(string $network)`
Fetches available data plans for a specific network.

**Parameters:**
- `$network` - Network identifier (e.g., 'mtn_sme_data')

#### `airtimeTopup(array $data)`
Executes airtime purchase on Peyflex API.

**Parameters:**
```php
$data = [
    'network' => 'mtn_nigeria',
    'mobile_number' => '08000000000',
    'amount' => 500,
];
```

#### `dataPurchase(array $data)`
Executes data purchase on Peyflex API.

**Parameters:**
```php
$data = [
    'network' => 'mtn_sme_data',
    'mobile_number' => '08000000000',
    'plan_code' => 'M500MBS',
];
```

## API Endpoints

### Airtime Endpoints

1. **Get Networks**
   - `GET /api/v1/user/peyflex/airtime/networks`
   - Returns available airtime networks

2. **Check Network**
   - `POST /api/v1/user/peyflex/airtime/check-network`
   - Validates network and mobile number
   - Request: `{ "network": "...", "mobile_number": "..." }`

3. **Purchase Airtime**
   - `POST /api/v1/user/peyflex/airtime/purchase`
   - Requires KYC verification
   - Request: `{ "network": "...", "mobile_number": "...", "amount": ... }`

### Data Endpoints

1. **Get Networks**
   - `GET /api/v1/user/peyflex/data/networks`
   - Returns available data networks

2. **Get Plans**
   - `POST /api/v1/user/peyflex/data/plans`
   - Request: `{ "network": "mtn_sme_data" }`

3. **Calculate Charges**
   - `POST /api/v1/user/peyflex/data/calculate-charges`
   - Request: `{ "network": "...", "plan_code": "...", "amount": ... }`
   - Returns calculated charges (percent + fixed)

4. **Purchase Data**
   - `POST /api/v1/user/peyflex/data/purchase`
   - Requires KYC verification
   - Request: `{ "network": "...", "mobile_number": "...", "plan_code": "...", "amount": ... }`

## Error Handling

### Production vs Development

**Development Mode:**
- Exceptions are thrown with detailed error messages
- Useful for debugging

**Production Mode:**
- Generic error messages returned to client
- Detailed errors logged server-side
- No sensitive information leaked

### Common Error Responses

```json
{
    "success": false,
    "errors": [
        "Insufficient balance"
    ]
}
```

### Error Types

1. **Validation Errors** (400)
   - Missing required fields
   - Invalid field formats

2. **Insufficient Balance** (402)
   - User wallet balance < payable amount

3. **Service Unavailable** (503)
   - Peyflex API unreachable
   - Transaction settings not configured

4. **Authorization Errors** (401)
   - KYC verification required

## Configuration

### Environment Variables

```env
PEYFLEX_API_KEY=your_api_key
PEYFLEX_SECRET_KEY=your_secret_key
PEYFLEX_PUBLIC_KEY=your_public_key
PEYFLEX_ENV=production
PEYFLEX_PRODUCTION_URL=https://client.peyflex.com.ng
PEYFLEX_SANDBOX_URL=https://sandbox.peyflex.com.ng
```

### Admin Settings

Configure in admin panel:

1. **Mobile Topup Settings**
   - Percent Charge: % of transaction amount
   - Fixed Charge: Fixed amount per transaction
   - Min/Max Limits: Transaction amount restrictions

2. **Data Bundle Settings**
   - Percent Charge: % of transaction amount
   - Fixed Charge: Fixed amount per transaction

## Transaction Types

### Database Schema

Transactions are recorded with:

- `type`: `PEYFLEX_AIRTIME` or `PEYFLEX_DATA`
- `trx_id`: Unique transaction ID (AT/DP + timestamp)
- `status`: SUCCESSFUL, PROCESSING, or FAILED
- `details`: JSON object containing API response and request data

### Transaction Details Structure

```json
{
    "network": "mtn_nigeria",
    "mobile_number": "08000000000",
    "api_transaction_id": "peyflex_id",
    "api_response": {
        "status": "SUCCESSFUL",
        "transaction_id": "...",
        ...
    }
}
```

## Caching Strategy

### Cached Endpoints

1. **Networks** - 24 hours
   - Cache key: `peyflex_airtime_networks_{env}_{type}`

2. **Data Plans** - 12 hours
   - Cache key: `peyflex_data_plans_{network}_{env}_{type}`

3. **Access Token** - 1 hour
   - Cache key: `PEYFLEX-API-ACCESS-TOKEN_{env}_{type}`

### Cache Invalidation

Manually clear cache when:
- Network or plan changes on Peyflex
- Provider credentials updated
- Switching between sandbox/production

```php
// Clear specific cache
cache()->forget('peyflex_airtime_networks_production_MOBILE-TOPUP');

// Clear all Peyflex cache
cache()->tags(['peyflex'])->flush();
```

## Testing

### API Testing Examples

**Using cURL:**

```bash
# Get airtime networks
curl -X GET "http://localhost:8000/api/v1/user/peyflex/airtime/networks" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json"

# Purchase airtime
curl -X POST "http://localhost:8000/api/v1/user/peyflex/airtime/purchase" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "network": "mtn_nigeria",
    "mobile_number": "08000000000",
    "amount": 500
  }'
```

**Using Postman:**

1. Set Authorization type to Bearer Token
2. Set headers: `Content-Type: application/json`
3. Send request to endpoint with JSON body

## Monitoring & Logging

### Key Logs to Monitor

1. API Communication Logs
   - Peyflex API requests/responses
   - Location: `storage/logs/laravel.log`

2. Transaction Logs
   - Transaction creation and status
   - Database: `transactions` table

3. Error Logs
   - Failed API calls
   - Balance insufficiency
   - Validation errors

### Monitoring Dashboard

Track:
- Success rate of transactions
- Average response time
- Network outages
- User balance changes

## Security Considerations

1. **API Key Protection**
   - Store in `.env` file, never commit
   - Use different keys for sandbox/production
   - Rotate keys regularly

2. **Rate Limiting**
   - Implemented via middleware
   - Prevents API abuse
   - Configurable per user

3. **KYC Requirement**
   - All purchase endpoints require KYC
   - Middleware: `api.kyc`
   - Validates user verification status

4. **Balance Verification**
   - Always check wallet balance before API call
   - Prevent double-charging
   - Atomic database transactions

## Troubleshooting

### Common Issues

1. **"Peyflex Provider Not Found!"**
   - Solution: Configure Peyflex API credentials in admin panel

2. **"Insufficient balance"**
   - Solution: User needs to add funds to wallet

3. **"Failed to fetch networks"**
   - Solution: Check Peyflex API connectivity and credentials

4. **"Invalid mobile number length"**
   - Solution: Ensure mobile number is 10-15 digits

5. **Timeout errors**
   - Solution: Increase API timeout or check Peyflex service status

## Migration from Mock to Production

The helper already includes logic to handle both:

1. **Check if response is successful**
   ```php
   if(isset($response['status']) && strtoupper($response['status']) === 'SUCCESSFUL')
   ```

2. **Proper error handling**
   - No fallback to mock data in production
   - Real exceptions thrown
   - Clear error messages

3. **Transaction recording**
   - All API responses logged
   - Transaction status tracked
   - Supports pending and processing states

## Support & Documentation

- Peyflex API Docs: `doc.txt`
- Base URL: `https://client.peyflex.com.ng`
- Authentication: Token-based (Authorization header)
- Support Contact: Peyflex Support Team

## Changelog

### v1.0.0 - Production Release

- ✅ Production-ready airtime purchase
- ✅ Production-ready data purchase
- ✅ Real error handling (no mock fallbacks)
- ✅ Token-based authentication
- ✅ Charge calculation system
- ✅ Transaction logging
- ✅ Email notifications
- ✅ KYC verification middleware
- ✅ Caching strategy
- ✅ Comprehensive error handling
