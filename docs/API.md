# JAWS API Reference

Complete documentation for the JAWS REST API, including authentication, endpoints, request/response formats, and error handling.

## Table of Contents

- [Overview](#overview)
- [Base URLs](#base-urls)
- [Authentication](#authentication)
- [Public Endpoints](#public-endpoints)
- [Authenticated Endpoints](#authenticated-endpoints)
- [Admin Endpoints](#admin-endpoints)
- [Error Handling](#error-handling)
- [Testing the API](#testing-the-api)

---

## Overview

The JAWS API is a REST/JSON API that provides programmatic access to the Social Day Cruising program management system. It supports:

- **Event Management**: View sailing events and schedules
- **Availability Updates**: Register boats and crew availability
- **Assignment Retrieval**: Get crew-to-boat assignments
- **Admin Operations**: Send notifications, manage configuration

All responses are in JSON format with consistent structure:

```json
{
  "success": true,
  "data": { ... }
}
```

Or for errors:

```json
{
  "success": false,
  "error": "Error message",
  "code": 400
}
```

---

## Base URLs

**Development:**
```
http://localhost:8000/api
```

**Production:**
```
https://your-domain.com/api
```

All endpoint paths in this documentation are relative to the base URL.

---

## Authentication

Most endpoints require JWT (JSON Web Token) authentication.

### Obtaining a Token

#### Register a New Account

**POST /api/auth/register**

Request Body:
```json
{
  "email": "user@example.com",
  "password": "your_secure_password",
  "accountType": "crew",
  "profile": {
    "firstName": "John",
    "lastName": "Doe",
    "skill": 1,
    "mobile": "555-1234"
  }
}
```

**Parameters:**
- `email` (string, required): User email address
- `password` (string, required): Password (minimum 8 characters)
- `accountType` (string, required): Either "boat" or "crew"
- `profile` (object, required): Account-specific profile data
  - For crew: `firstName`, `lastName`, `skill` (0=Novice, 1=Intermediate, 2=Advanced)
  - For boat: `displayName`, `ownerFirstName`, `ownerLastName`, `minBerths`, `maxBerths`

Response:
```json
{
  "success": true,
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "user": {
      "id": 1,
      "email": "user@example.com",
      "accountType": "crew"
    }
  }
}
```

#### Login with Existing Account

**POST /api/auth/login**

Request Body:
```json
{
  "email": "user@example.com",
  "password": "your_password"
}
```

Response:
```json
{
  "success": true,
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "user": {
      "id": 1,
      "email": "user@example.com",
      "accountType": "crew",
      "isAdmin": false
    }
  }
}
```

### Using the Token

Include the token in the `Authorization` header for authenticated requests:

```http
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

**Example with curl:**
```bash
curl -X GET "http://localhost:8000/api/users/me" \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc..."
```

**Example with JavaScript:**
```javascript
const token = localStorage.getItem('jaws_token');

fetch('/api/users/me', {
    headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json'
    }
});
```

### Token Expiration

Tokens expire after 60 minutes by default (configurable via `JWT_EXPIRATION_MINUTES` environment variable).

When a token expires, you'll receive a 401 Unauthorized response. Simply login again to get a new token.

---

## Public Endpoints

These endpoints do not require authentication.

### GET /api/events

List all sailing events for the season.

**Request:**
```bash
curl -X GET "http://localhost:8000/api/events"
```

**Response:**
```json
{
  "success": true,
  "data": {
    "events": [
      {
        "event_id": "Fri May 29",
        "event_date": "2026-05-29",
        "start_time": "12:45:00",
        "finish_time": "17:00:00",
        "status": "upcoming"
      },
      {
        "event_id": "Fri Jun 05",
        "event_date": "2026-06-05",
        "start_time": "12:45:00",
        "finish_time": "17:00:00",
        "status": "upcoming"
      }
    ]
  }
}
```

**Event Fields:**
- `event_id` (string): Unique event identifier (e.g., "Fri May 29")
- `event_date` (string): ISO 8601 date (YYYY-MM-DD)
- `start_time` (string): Event start time (HH:MM:SS)
- `finish_time` (string): Event finish time (HH:MM:SS)
- `status` (string): "upcoming", "in_progress", or "completed"

---

### GET /api/events/{id}

Get details for a specific event, including flotilla assignments.

**Request:**
```bash
curl -X GET "http://localhost:8000/api/events/Fri%20May%2029"
```

**Parameters:**
- `id` (path parameter): URL-encoded event_id (e.g., "Fri%20May%2029" for "Fri May 29")

**Response:**
```json
{
  "success": true,
  "data": {
    "event": {
      "event_id": "Fri May 29",
      "event_date": "2026-05-29",
      "start_time": "12:45:00",
      "finish_time": "17:00:00"
    },
    "crewed_boats": [
      {
        "boat_key": "sailaway",
        "display_name": "Sail Away",
        "owner_first_name": "John",
        "owner_last_name": "Doe",
        "crew": [
          {
            "crew_key": "jane_smith",
            "first_name": "Jane",
            "last_name": "Smith",
            "skill": 1
          }
        ]
      }
    ],
    "waitlist_boats": [],
    "waitlist_crews": []
  }
}
```

**Response Fields:**
- `crewed_boats` (array): Boats with assigned crew
- `waitlist_boats` (array): Boats without sufficient crew
- `waitlist_crews` (array): Crew without boat assignments

---

## Authenticated Endpoints

These endpoints require a valid JWT token in the Authorization header.

### PATCH /api/users/me/availability

Update your availability for sailing events. The endpoint auto-detects whether you are a boat owner, crew member, or both (flex member) and updates all applicable entities.

**Request:**
```bash
curl -X PATCH "http://localhost:8000/api/users/me/availability" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "availabilities": {
      "Fri May 29": 1,
      "Fri Jun 05": 2
    }
  }'
```

**Request Body:**
```json
{
  "availabilities": {
    "Fri May 29": 1,
    "Fri Jun 05": 2,
    "Fri Jun 12": 0
  }
}
```

**Availability Values:**

For **boat owners**, values represent berths (capacity offered):
- `0` - Not offering boat
- `1-5` - Number of berths offered

For **crew members**, values represent availability status:
- `0` - UNAVAILABLE (cannot participate)
- `1` - AVAILABLE (can participate)
- `2` - GUARANTEED (assigned by system)
- `3` - WITHDRAWN (explicitly withdrawn)

For **flex members** (both boat owner and crew), the same values are used for both boat berths AND crew status.

**Response (Crew Member):**
```json
{
  "success": true,
  "updated": ["crew"],
  "crew": {
    "key": "john_doe",
    "firstName": "John",
    "lastName": "Doe",
    "email": "john@example.com",
    "skill": 1,
    "availabilities": {
      "Fri May 29": 1,
      "Fri Jun 05": 2,
      "Fri Jun 12": 0
    }
  },
  "message": "Availability updated successfully"
}
```

**Response (Boat Owner):**
```json
{
  "success": true,
  "updated": ["boat"],
  "boat": {
    "key": "sailaway",
    "displayName": "Sail Away",
    "ownerFirstName": "Jane",
    "ownerLastName": "Smith",
    "availabilities": {
      "Fri May 29": 2,
      "Fri Jun 05": 3
    }
  },
  "message": "Availability updated successfully"
}
```

**Response (Flex Member):**
```json
{
  "success": true,
  "updated": ["boat", "crew"],
  "boat": { ... },
  "crew": { ... },
  "message": "Availability updated successfully"
}
```

---

### GET /api/assignments

Get your crew assignments across all events.

**Request:**
```bash
curl -X GET "http://localhost:8000/api/assignments" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Response:**
```json
{
  "success": true,
  "data": {
    "assignments": [
      {
        "event_id": "Fri May 29",
        "event_date": "2026-05-29",
        "boat_key": "sailaway",
        "boat_name": "Sail Away",
        "owner_name": "John Doe"
      },
      {
        "event_id": "Fri Jun 05",
        "event_date": "2026-06-05",
        "boat_key": "windseeker",
        "boat_name": "Wind Seeker",
        "owner_name": "Jane Smith"
      }
    ]
  }
}
```

**Assignment Fields:**
- `event_id` (string): Event identifier
- `event_date` (string): ISO 8601 date
- `boat_key` (string): Assigned boat identifier
- `boat_name` (string): Assigned boat display name
- `owner_name` (string): Boat owner full name

---

### POST /api/assignments/crew-flags

Boat owner only. Flags crew members who were assigned to the caller's boat,
decrementing each flagged crew's `commitment_rank` by the number of times
they were flagged (clamped to 0-2). Each `(eventId, crewKey)` pair is
independently verified against the persisted flotilla for that event — a
boat owner can only flag crew genuinely assigned to their own boat.

**Request:**
```bash
curl -X POST "http://localhost:8000/api/assignments/crew-flags" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "flags": [
      { "eventId": "Fri May 29", "crewKey": "johndoe" },
      { "eventId": "Fri Jun 05", "crewKey": "johndoe" }
    ]
  }'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "flagged": [
      {
        "crew_key": "johndoe",
        "display_name": "John Doe",
        "flag_count": 2,
        "rank_commitment": 0
      }
    ]
  }
}
```

Flags for `(eventId, crewKey)` pairs that don't match a real assignment to
the caller's boat are silently dropped and never appear in the response.

---

### GET /api/flotillas

Get all flotillas for all events.

**Request:**
```bash
curl -X GET "http://localhost:8000/api/flotillas" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Response:**
```json
{
  "success": true,
  "data": {
    "flotillas": [
      {
        "event_id": "Fri May 29",
        "event_date": "2026-05-29",
        "crewed_boats": [...],
        "waitlist_boats": [...],
        "waitlist_crews": [...]
      }
    ]
  }
}
```

---

### GET /api/users/me/availability

Get your current availability status across all events.

**Request:**
```bash
curl -X GET "http://localhost:8000/api/users/me/availability" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Response:**
```json
{
  "success": true,
  "data": {
    "availabilities": {
      "Fri May 29": 1,
      "Fri Jun 05": 2,
      "Fri Jun 12": 0
    }
  }
}
```

---

### GET /api/users/me

Get your user profile (boat owner or crew).

**Request:**
```bash
curl -X GET "http://localhost:8000/api/users/me" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Response (Crew Member):**
```json
{
  "success": true,
  "data": {
    "accountType": "crew",
    "profile": {
      "key": "john_doe",
      "firstName": "John",
      "lastName": "Doe",
      "email": "john@example.com",
      "mobile": "555-1234",
      "skill": 1,
      "membershipNumber": "12345"
    }
  }
}
```

**Response (Boat Owner):**
```json
{
  "success": true,
  "data": {
    "accountType": "boat",
    "profile": {
      "key": "sailaway",
      "displayName": "Sail Away",
      "ownerFirstName": "Jane",
      "ownerLastName": "Smith",
      "ownerEmail": "jane@example.com",
      "ownerMobile": "555-5678",
      "minBerths": 1,
      "maxBerths": 3,
      "assistanceRequired": false
    }
  }
}
```

---

### POST /api/users/me

Add a new profile (after registration, create boat or crew profile).

**Request (Crew):**
```bash
curl -X POST "http://localhost:8000/api/users/me" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "accountType": "crew",
    "profile": {
      "firstName": "John",
      "lastName": "Doe",
      "skill": 1,
      "mobile": "555-1234",
      "membershipNumber": "12345"
    }
  }'
```

**Request (Boat):**
```bash
curl -X POST "http://localhost:8000/api/users/me" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "accountType": "boat",
    "profile": {
      "displayName": "Sail Away",
      "ownerFirstName": "Jane",
      "ownerLastName": "Smith",
      "minBerths": 1,
      "maxBerths": 3,
      "ownerMobile": "555-5678",
      "assistanceRequired": false
    }
  }'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "accountType": "crew",
    "profile": { ... }
  }
}
```

---

### PATCH /api/users/me

Update your user profile.

**Request:**
```bash
curl -X PATCH "http://localhost:8000/api/users/me" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "profile": {
      "mobile": "555-9999",
      "skill": 2
    }
  }'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "accountType": "crew",
    "profile": {
      "key": "john_doe",
      "firstName": "John",
      "lastName": "Doe",
      "mobile": "555-9999",
      "skill": 2
    }
  }
}
```

---

### GET /api/auth/session

Get current session information.

**Request:**
```bash
curl -X GET "http://localhost:8000/api/auth/session" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Response:**
```json
{
  "success": true,
  "data": {
    "user": {
      "id": 1,
      "email": "john@example.com",
      "accountType": "crew",
      "isAdmin": false
    }
  }
}
```

---

### POST /api/auth/logout

Logout and invalidate the JWT token.

**Request:**
```bash
curl -X POST "http://localhost:8000/api/auth/logout" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Response:**
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

---

## Admin Endpoints

These endpoints require admin privileges (JWT token with `is_admin: true`).

### GET /api/admin/matching/{eventId}

Get matching data for an event (available boats, crews, capacity analysis).

**Request:**
```bash
curl -X GET "http://localhost:8000/api/admin/matching/Fri%20May%2029" \
  -H "Authorization: Bearer ADMIN_JWT_TOKEN"
```

**Response:**
```json
{
  "success": true,
  "data": {
    "event_id": "Fri May 29",
    "available_boats": 12,
    "available_crews": 25,
    "total_capacity": 36,
    "boats": [...],
    "crews": [...]
  }
}
```

---

### POST /api/admin/notifications/{eventId}

Send email notifications for an event to all assigned crew.

**Request:**
```bash
curl -X POST "http://localhost:8000/api/admin/notifications/Fri%20May%2029" \
  -H "Authorization: Bearer ADMIN_JWT_TOKEN" \
  -H "Content-Type: application/json"
```

**Response:**
```json
{
  "success": true,
  "data": {
    "sent": 25,
    "failed": 0
  }
}
```

---

### PATCH /api/admin/config

Update season configuration (event times, blackout windows, etc.).

**Request:**
```bash
curl -X PATCH "http://localhost:8000/api/admin/config" \
  -H "Authorization: Bearer ADMIN_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "year": 2026,
    "source": "production",
    "start_time": "12:45:00",
    "finish_time": "17:00:00",
    "blackout_from": "10:00:00",
    "blackout_to": "18:00:00"
  }'
```

**Request Body Parameters:**
- `year` (integer): Season year
- `source` (string): "production" or "simulated" (for testing)
- `start_time` (string): Default event start time (HH:MM:SS)
- `finish_time` (string): Default event finish time (HH:MM:SS)
- `blackout_from` (string): Start of blackout window (HH:MM:SS)
- `blackout_to` (string): End of blackout window (HH:MM:SS)

**Response:**
```json
{
  "success": true,
  "data": {
    "config": {
      "year": 2026,
      "source": "production",
      "start_time": "12:45:00",
      "finish_time": "17:00:00",
      "blackout_from": "10:00:00",
      "blackout_to": "18:00:00"
    }
  }
}
```

---

## Error Handling

All errors return a JSON response with `success: false`:

```json
{
  "success": false,
  "error": "Error message describing what went wrong",
  "code": 400
}
```

### HTTP Status Codes

- **200 OK**: Successful request
- **201 Created**: Resource created successfully
- **400 Bad Request**: Invalid request data (validation error)
- **401 Unauthorized**: Missing or invalid authentication token
- **403 Forbidden**: Insufficient permissions or blackout window
- **404 Not Found**: Resource not found
- **500 Internal Server Error**: Server error

### Common Error Responses

**400 Bad Request - Validation Error:**
```json
{
  "success": false,
  "error": "Availabilities are required",
  "code": 400
}
```

**401 Unauthorized - Missing Token:**
```json
{
  "success": false,
  "error": "Unauthorized: No token provided",
  "code": 401
}
```

**401 Unauthorized - Invalid Token:**
```json
{
  "success": false,
  "error": "Unauthorized: Invalid token",
  "code": 401
}
```

**403 Forbidden - Blackout Window:**
```json
{
  "success": false,
  "error": "Registration is blocked during event hours (10:00-18:00)",
  "code": 403
}
```

**404 Not Found - Boat Not Found:**
```json
{
  "success": false,
  "error": "Boat not found for owner: John Doe",
  "code": 404
}
```

**404 Not Found - Crew Not Found:**
```json
{
  "success": false,
  "error": "Crew not found: John Doe",
  "code": 404
}
```

**404 Not Found - Event Not Found:**
```json
{
  "success": false,
  "error": "Event not found: Fri May 29",
  "code": 404
}
```

**500 Internal Server Error:**
```json
{
  "success": false,
  "error": "An unexpected error occurred",
  "code": 500
}
```

---

## Testing the API

### Using Postman

JAWS includes a Postman collection for easy API testing.

1. **Import Collection:**
   - Open Postman
   - File → Import
   - Select `tests/JAWS_API.postman_collection.json`

2. **Configure Environment:**
   - Create new environment
   - Add variable `baseUrl` with value `http://localhost:8000/api`
   - Add variable `token` (will be set automatically after login)

3. **Run Collection:**
   - Select the JAWS API collection
   - Click "Run" to execute all requests

### Using cURL

**Example: Register and Get Profile**

```bash
# 1. Register new account
curl -X POST "http://localhost:8000/api/auth/register" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "password": "testpassword123",
    "accountType": "crew",
    "profile": {
      "firstName": "Test",
      "lastName": "User",
      "skill": 1
    }
  }'

# Save the token from response
TOKEN="eyJ0eXAiOiJKV1QiLCJhbGc..."

# 2. Get profile
curl -X GET "http://localhost:8000/api/users/me" \
  -H "Authorization: Bearer $TOKEN"

# 3. Update availability
curl -X PATCH "http://localhost:8000/api/users/me/availability" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "availabilities": {
      "Fri May 29": 1,
      "Fri Jun 05": 2
    }
  }'

# 4. Get assignments
curl -X GET "http://localhost:8000/api/assignments" \
  -H "Authorization: Bearer $TOKEN"
```

### Using JavaScript

**Example: Frontend Integration**

```javascript
// Login
async function login(email, password) {
    const response = await fetch('/api/auth/login', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ email, password })
    });

    const data = await response.json();

    if (data.success) {
        localStorage.setItem('jaws_token', data.data.token);
        return data.data.user;
    } else {
        throw new Error(data.error);
    }
}

// Update availability
async function updateAvailability(availabilities) {
    const token = localStorage.getItem('jaws_token');

    const response = await fetch('/api/users/me/availability', {
        method: 'PATCH',
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ availabilities })
    });

    const data = await response.json();

    if (!data.success) {
        throw new Error(data.error);
    }

    return data;
}

// Get assignments
async function getAssignments() {
    const token = localStorage.getItem('jaws_token');

    const response = await fetch('/api/assignments', {
        headers: {
            'Authorization': `Bearer ${token}`
        }
    });

    const data = await response.json();

    if (data.success) {
        return data.data.assignments;
    } else {
        throw new Error(data.error);
    }
}
```

### API Test Suite

JAWS includes a PHPUnit API test suite:

```bash
# Start development server
php -S localhost:8000 -t public &

# Run API tests
./vendor/bin/phpunit --testsuite=API
```

---

## Next Steps

Now that you understand the JAWS API:

✅ API endpoints learned!
➡️ **Next:** Read [Developer Guide](DEVELOPER_GUIDE.md) - Learn about the codebase architecture

✅ Authentication working!
➡️ **Next:** Read [Frontend Setup](FRONTEND_SETUP.md) - Integrate with your frontend

✅ Testing complete!
➡️ **Next:** Read [Deployment Guide](DEPLOYMENT.md) - Deploy to production

---

📖 **Additional Resources:**

- [Setup Guide](SETUP.md) - Installation and configuration
- [Developer Guide](DEVELOPER_GUIDE.md) - Architecture and development workflow
- [Contributing Guide](CONTRIBUTING.md) - Code style and Git workflow
- [CLAUDE.md](../CLAUDE.md) - Complete technical specifications
