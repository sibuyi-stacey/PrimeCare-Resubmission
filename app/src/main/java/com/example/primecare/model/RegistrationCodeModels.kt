package com.example.primecare.model

/**
 * Request sent by an administrator when generating
 * a registration code for a new staff member.
 */
data class GenerateRegistrationCodeRequest(
    val email: String,
    val role: String
)

/**
 * Response received after the server generates
 * a registration code.
 */
data class RegistrationCodeResponse(
    val success: Boolean,
    val message: String?,
    val code: String?,
    val email: String?,
    val role: String?,
    val expiresAt: String?
)
