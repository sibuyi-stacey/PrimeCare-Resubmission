
<?php
/**
 * POST /api/staff/auth/register.php
 * Body: { firstName, lastName, email, password, role, registrationCode }
 *
 * - Passwords are stored securely using password_hash().
 * - Doctor registration also creates a matching doctors record.
 * - Registration codes must match the email and role, be valid,
 *   and must not have expired or been used already.
 */

require_once __DIR__ . '/../../../includes/staff-auth.php';
require_once __DIR__ . '/../../../includes/rate-limit.php';

require_method('POST');
check_rate_limit($pdo, 'register', 10, 15);

$in    = read_json();
$first = trim((string) ($in['firstName'] ?? ''));
$last  = trim((string) ($in['lastName'] ?? ''));
$email = strtolower(trim((string) ($in['email'] ?? '')));
$pass  = (string) ($in['password'] ?? '');
$role  = strtoupper(trim((string) ($in['role'] ?? '')));
$code  = trim((string) ($in['registrationCode'] ?? ''));

record_attempt($pdo, 'register');

// Validate the staff member's personal details.
if ($first === '' || $last === '' || mb_strlen($first) > 50 || mb_strlen($last) > 50) {
    fail('Please enter your first and last name (max 50 characters each).', 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
    fail('Please enter a valid email address.', 422);
}

if (($err = password_error($pass)) !== null) {
    fail($err, 422);
}

if (!in_array($role, ['ADMIN', 'DOCTOR'], true)) {
    fail('Please choose a role.', 422);
}

if ($code === '') {
    fail('Please enter your clinic registration code.', 422);
}

try {
    // Start one database transaction for the entire registration.
    $pdo->beginTransaction();

    // Hash the supplied code to compare it with the stored hash.
    $codeHash = hash('sha256', $code);

    // Verify that the code belongs to this email and role and is not expired.
    // FOR UPDATE locks the matching code while registration is in progress.
    $codeCheck = $pdo->prepare(
        'SELECT code_hash
         FROM staff_registration_codes
         WHERE code_hash = :hash
           AND intended_email = :email
           AND role = :role
           AND expires_at > :now
         LIMIT 1
         FOR UPDATE'
    );

    $codeCheck->execute([
        'hash'  => $codeHash,
        'email' => $email,
        'role'  => $role,
        'now'   => date('Y-m-d H:i:s')
    ]);

    if (!$codeCheck->fetch()) {
        $pdo->rollBack();
        fail('The registration code is invalid, expired, or does not match this email and role.', 403);
    }

    // Prevent duplicate staff or doctor accounts.
    $exists = $pdo->prepare(
        'SELECT 1 FROM staff WHERE email = :e
         UNION
         SELECT 1 FROM doctors WHERE email = :e2'
    );

    $exists->execute([
        'e'  => $email,
        'e2' => $email
    ]);

    if ($exists->fetch()) {
        $pdo->rollBack();
        fail('An account with that email already exists.', 409);
    }

    // Create the matching doctor record when the selected role is DOCTOR.
    $doctorId = null;

    if ($role === 'DOCTOR') {
        $dep = $pdo->query(
            "SELECT department_id
             FROM departments
             WHERE department_name = 'General Practice'
             LIMIT 1"
        )->fetchColumn();

        $pdo->prepare(
            "INSERT INTO doctors
             (first_name, last_name, specialty, department_id, email, status)
             VALUES (:f, :l, 'General Practice', :d, :e, 'Active')"
        )->execute([
            'f' => $first,
            'l' => $last,
            'd' => $dep ?: null,
            'e' => $email
        ]);

        $doctorId = (int) $pdo->lastInsertId();
    }

    // Create the staff account with a securely hashed password.
    $pdo->prepare(
        'INSERT INTO staff
         (email, password_hash, role, first_name, last_name, doctor_id)
         VALUES (:e, :h, :r, :f, :l, :d)'
    )->execute([
        'e' => $email,
        'h' => password_hash($pass, PASSWORD_DEFAULT),
        'r' => $role,
        'f' => $first,
        'l' => $last,
        'd' => $doctorId
    ]);

    // Consume the registration code so it cannot be used again.
    $consumeCode = $pdo->prepare(
        'DELETE FROM staff_registration_codes WHERE code_hash = :hash'
    );

    $consumeCode->execute(['hash' => $codeHash]);

    // Save all changes together.
    $pdo->commit();

    json_out([
        'success' => true,
        'message' => 'Account created. You can now log in.'
    ], 201);

} catch (PDOException $e) {

    // Undo all database changes if registration fails.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fail('Could not create the account. Please try again.', 500);
}
