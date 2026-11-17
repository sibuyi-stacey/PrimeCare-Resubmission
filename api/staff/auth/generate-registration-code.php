
<?php
/**
 * PrimeCare Medical Centre
 * Staff Registration Code Generation
 *
 * Endpoint: POST /api/staff/auth/generate-registration-code.php
 *
 * Purpose:
 * Allows an authenticated administrator to generate a unique
 * registration code for a new staff member.
 *
 * Request body:
 * {
 *     "email": "doctor@primecare.com",
 *     "role": "DOCTOR"
 * }
 *
 * Security features:
 * - Only administrators can generate registration codes.
 * - Each code is linked to one email address and staff role.
 * - Registration codes are randomly generated.
 * - Only the SHA-256 hash is stored in the database.
 * - Codes expire after 24 hours.
 */

 // Include shared authentication functions and database connection.
require_once __DIR__ . '/../../../includes/staff-auth.php';

// Ensure that this endpoint only accepts POST requests.
require_method('POST');

// Verify that the user is logged in and has administrator privileges.
// The returned staff record contains the administrator's staff_id.
$staff = require_staff($pdo, ['ADMIN']);

// Read the JSON data submitted by the Android application.
$in = read_json();

// Retrieve and clean the staff member's email address.
$email = strtolower(trim((string) ($in['email'] ?? '')));

// Retrieve and standardise the requested staff role.
$role = strtoupper(trim((string) ($in['role'] ?? '')));

// Validate the email address before continuing.
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
    fail('Please enter a valid staff email address.', 422);
}

// Only ADMIN and DOCTOR registration codes are permitted.
if (!in_array($role, ['ADMIN', 'DOCTOR'], true)) {
    fail('Please select a valid staff role.', 422);
}

// Check whether an account with this email already exists.
// Existing staff and doctor accounts must not receive new codes.
$exists = $pdo->prepare(
    'SELECT 1 FROM staff WHERE email = :e
     UNION
     SELECT 1 FROM doctors WHERE email = :e2'
);

$exists->execute([
    'e' => $email,
    'e2' => $email
]);

// Stop the process if the email is already registered.
if ($exists->fetch()) {
    fail('An account with this email already exists.', 409);
}

// Generate a unique, unpredictable registration code.
// The PC prefix identifies the code as a PrimeCare registration code.
$code = 'PC-' . strtoupper(bin2hex(random_bytes(4)));

// Hash the registration code using SHA-256.
// The original code will not be stored in the database.
$codeHash = hash('sha256', $code);

// Set the registration code to expire after 24 hours.
$expires = date('Y-m-d H:i:s', time() + 86400);

try {

    // Prepare an SQL statement to save the registration code.
    // The code is associated with the intended email, role and administrator.
    $stmt = $pdo->prepare(
        'INSERT INTO staff_registration_codes
            (code_hash, intended_email, role, created_by, expires_at)
         VALUES (:hash, :email, :role, :creator, :expires)'
    );

    // Execute the statement using securely bound parameters.
    $stmt->execute([
        'hash' => $codeHash,
        'email' => $email,
        'role' => $role,
        'creator' => $staff['staff_id'],
        'expires' => $expires
    ]);

    // Return the generated code to the administrator.
    // The original code is only revealed in this response.
    json_out([
        'success' => true,
        'message' => 'Registration code generated successfully.',
        'code' => $code,
        'email' => $email,
        'role' => $role,
        'expiresAt' => $expires
    ], 201);

} catch (PDOException $e) {

    // Return an error if the registration code could not be saved.
    fail('Could not generate the registration code. Please try again.', 500);
}
