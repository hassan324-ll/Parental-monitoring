package com.familyguard.child.ui

import android.os.Build
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.familyguard.child.data.PairingRepository
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

/** Intentionally keeps issued credential in memory only until secure storage is implemented. */
class PairingViewModel : ViewModel() {
    private val api = PairingRepository()
    private val _state = MutableStateFlow(State())
    val state = _state.asStateFlow()

    data class State(
        val code: String = "",
        val preview: PairingRepository.Preview? = null,
        val busy: Boolean = false,
        val message: String = "",
        val pairedThisSession: Boolean = false
    )

    fun updateCode(input: String) {
        _state.value = State(code = input.uppercase().filter { it in "0123456789ABCDEF" }.take(32))
    }

    fun preview() {
        val code = state.value.code
        if (code.length != 32 || state.value.busy) return
        _state.value = state.value.copy(busy = true, message = "")
        viewModelScope.launch {
            try {
                val preview = api.preview(code)
                _state.value = state.value.copy(preview = preview, busy = false)
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                _state.value = state.value.copy(busy = false, message = "Invitation invalid, expired, or server unavailable.")
            }
        }
    }

    fun cancel() { _state.value = State(message = "Pairing cancelled. No approval sent.") }

    // Approval intentionally disabled until Keystore-backed credential persistence is ready.
    // Otherwise a consumed one-time code would create an inaccessible paired device.
    fun approve() {
        _state.value = state.value.copy(
            message = "Approval is not yet available. Secure device credential storage is required first."
        )
    }
}
