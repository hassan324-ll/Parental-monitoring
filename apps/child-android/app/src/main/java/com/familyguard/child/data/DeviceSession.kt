package com.familyguard.child.data

import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

/** No credential enters observable UI state. All restored sessions require server validation. */
class DeviceSession(
    private val credentials: DeviceCredentialStore,
    private val api: CompanionSessionApi
) {
    enum class Phase { UNPAIRED, CHECKING, CONNECTED, OFFLINE, DISCONNECTING, ERROR }
    data class State(
        val phase: Phase = Phase.UNPAIRED,
        val device: CompanionDevice? = null,
        val message: String = "No device session has been verified."
    )
    private val lock = Mutex()
    private val mutableState = MutableStateFlow(State())
    val state = mutableState.asStateFlow()

    suspend fun restore() = lock.withLock {
        mutableState.value = State(Phase.CHECKING, message = "Checking device authorization…")
        try {
            val token = readCredential() ?: return@withLock
            try {
                val device = api.status(token)
                mutableState.value = State(Phase.CONNECTED, device, "Device authorization verified. No monitoring is active.")
            } catch (failure: DeviceUnauthorizedException) {
                clearRejectedCredential("Device authorization ended. Pair again to reconnect.")
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (failure: Exception) {
                mutableState.value = State(Phase.OFFLINE, message = "Cannot verify device authorization. Check the connection and retry.")
            }
        } catch (cancelled: CancellationException) {
            mutableState.value = State(Phase.OFFLINE, message = "Authorization check interrupted. Retry to verify this device.")
            throw cancelled
        }
    }

    suspend fun disconnect() = lock.withLock {
        mutableState.value = State(Phase.DISCONNECTING, message = "Disconnecting device…")
        try {
            val token = readCredential() ?: return@withLock
            try {
                api.disconnect(token)
            } catch (failure: DeviceUnauthorizedException) {
                // Already revoked is a successful disconnect; local cleanup is still required.
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (failure: Exception) {
                mutableState.value = State(Phase.OFFLINE, message = "Server disconnect is unconfirmed. Retry, or revoke this device from the parent account.")
                return@withLock
            }
            clearRejectedCredential("Device disconnected. Local credentials removed.")
        } catch (cancelled: CancellationException) {
            mutableState.value = State(Phase.OFFLINE, message = "Disconnect interrupted. Server status is unknown; retry or revoke from the parent account.")
            throw cancelled
        }
    }

    private suspend fun readCredential(): String? {
        return try {
            val token = credentials.read()
            if (token == null) mutableState.value = State(message = "This device is not paired.")
            token
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (failure: Exception) {
            mutableState.value = State(Phase.ERROR, message = "Stored credentials unavailable. Revoke this device from the parent account before re-pairing.")
            null
        }
    }

    private suspend fun clearRejectedCredential(message: String) {
        try {
            credentials.clear()
            mutableState.value = State(message = message)
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (failure: Exception) {
            mutableState.value = State(Phase.ERROR, message = "Server access ended, but local credential cleanup failed. Retry disconnect.")
        }
    }
}