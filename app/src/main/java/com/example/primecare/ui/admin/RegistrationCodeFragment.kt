package com.example.primecare.ui.admin

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.os.Bundle
import android.util.Patterns
import android.view.View
import android.widget.ArrayAdapter
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import com.example.primecare.data.local.SessionManager
import com.example.primecare.data.repository.AuthRepository
import com.example.primecare.data.repository.DataResult
import com.example.primecare.databinding.FragmentRegistrationCodeBinding
import com.example.primecare.ui.common.BaseFragment
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.launch

/**
 * PrimeCare - Staff Registration Code Screen
 *
 * Allows an authenticated administrator to generate
 * a registration code for a new staff member.
 */
class RegistrationCodeFragment :
    BaseFragment<FragmentRegistrationCodeBinding>(
        FragmentRegistrationCodeBinding::inflate
    ) {

    // Repository responsible for communicating with the PHP API.
    private val authRepository by lazy {
        AuthRepository(SessionManager(requireContext()))
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        // Configure the screen title.
        binding.topBar.tvTitle.text = "Staff Registration"

        // Return to the previous screen.
        binding.topBar.btnBack.setOnClickListener {
            findNavController().navigateUp()
        }

        // Available staff roles.
        val roles = listOf("DOCTOR", "ADMIN")

        // Populate the role selection spinner.
        val adapter = ArrayAdapter(
            requireContext(),
            android.R.layout.simple_spinner_item,
            roles
        )

        adapter.setDropDownViewResource(
            android.R.layout.simple_spinner_dropdown_item
        )

        binding.spStaffRole.adapter = adapter

        // Hide the generated code card until a code is created.
        binding.cardGeneratedCode.visibility = View.GONE

        // Generate a registration code when the button is clicked.
        binding.btnGenerateCode.setOnClickListener {
            generateCode()
        }

        // Copy the generated code to the clipboard.
        binding.btnCopyCode.setOnClickListener {
            copyCode()
        }
    }

    /**
     * Validates the email and requests a registration code
     * from the PHP backend.
     */
    private fun generateCode() {

        val email = binding.etStaffEmail.text
            ?.toString()
            ?.trim()
            .orEmpty()

        val role = binding.spStaffRole.selectedItem
            ?.toString()
            .orEmpty()

        // Check that an email address was entered.
        if (email.isBlank()) {
            binding.etStaffEmail.error = "Enter the staff email address"
            return
        }

        // Validate the email format.
        if (!Patterns.EMAIL_ADDRESS.matcher(email).matches()) {
            binding.etStaffEmail.error = "Enter a valid email address"
            return
        }

        // Prevent duplicate requests while one is processing.
        binding.btnGenerateCode.isEnabled = false

        viewLifecycleOwner.lifecycleScope.launch {

            try {

                // Send the request through AuthRepository.
                when (
                    val result = authRepository.generateRegistrationCode(
                        email = email,
                        role = role
                    )
                ) {

                    is DataResult.Success -> {

                        val response = result.data

                        if (response.success && !response.code.isNullOrBlank()) {

                            // Display the generated registration code.
                            binding.tvGeneratedCode.text = response.code

                            // Display the expiry information.
                            binding.tvCodeExpiry.text =
                                "Expires: ${response.expiresAt ?: "Within 24 hours"}"

                            binding.cardGeneratedCode.visibility = View.VISIBLE

                            showMessage("Registration code generated successfully.")

                        } else {
                            showMessage(
                                response.message?.takeIf { it.isNotBlank() }
                                    ?: "Could not generate registration code."
                            )
                        }
                    }

                    is DataResult.Error -> {
                        showMessage(result.message)
                    }
                }

            } catch (e: Exception) {

                showMessage(
                    e.message ?: "Something went wrong. Please try again."
                )

            } finally {

                // Allow another request.
                binding.btnGenerateCode.isEnabled = true
            }
        }
    }

    /**
     * Copies the generated registration code.
     */
    private fun copyCode() {

        val code = binding.tvGeneratedCode.text.toString()

        if (code.isBlank()) {
            showMessage("No registration code available.")
            return
        }

        val clipboard = requireContext().getSystemService(
            Context.CLIPBOARD_SERVICE
        ) as ClipboardManager

        val clip = ClipData.newPlainText(
            "PrimeCare Registration Code",
            code
        )

        clipboard.setPrimaryClip(clip)

        showMessage("Registration code copied.")
    }

    /**
     * Displays feedback to the administrator.
     */
    private fun showMessage(message: String) {
        Snackbar.make(
            binding.root,
            message,
            Snackbar.LENGTH_LONG
        ).show()
    }
}
