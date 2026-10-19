package com.familyguard.child.data

import java.security.MessageDigest
import java.security.SecureRandom
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

interface CompanionRotationApi {
    suspend fun rotate(currentCredential: String, nextCredentialHash: String)
}

internal object CredentialTokens {
    fun generate(): String = ByteArray(32).also { SecureRandom().nextBytes(it) }.toHex()
    fun hash(token: String): String = MessageDigest.getInstance("SHA-256")
        .digest(token.toByteArray(Charsets.UTF_8)).toHex()
    private fun ByteArray.toHex() = joinToString("") { "%02x".format(it.toInt() and 255) }
}

/** Session locking wraps this helper; its lock also protects direct recovery callers. */
class DeviceRotation(
    private val store: RotatingDeviceCredentialStore,
    private val api: CompanionSessionApi,
    private val rotationApi: CompanionRotationApi,
    private val generate: () -> String = CredentialTokens::generate
) {
    private val lock = Mutex()

    suspend fun rotate(): DeviceSession.State = lock.withLock {
        try {
            var journal = store.readJournal() ?: return@withLock DeviceSession.State(message = "This device is not paired.")
            if (journal.pending == null) {
                try {
                    api.status(journal.current)
                } catch (revoked: DeviceUnauthorizedException) {
                    store.clear()
                    return@withLock DeviceSession.State(message = "Device authorization ended. Pair again to reconnect.")
                }
                store.prepare()
                val pending = generate()
                val staged = CredentialJournal(journal.current, pending)
                store.stage(staged.current, pending)
                val readBack = store.readJournal()
                check(readBack?.current == staged.current && readBack.pending == pending) {
                    "Rotation staging read-back failed."
                }
                journal = staged
            }
            try {
                rotationApi.rotate(journal.current, CredentialTokens.hash(requireNotNull(journal.pending)))
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (uncertain: Exception) {
                // A lost response or stale old bearer is resolved using the SAME durable candidate.
            }
            resolve(journal)
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (failure: Exception) {
            recovery("Rotation could not complete. Keep recovery data and retry, or revoke from the parent account.")
        }
    }

    /** Startup verifies candidates only; it never silently generates or sends another rotation. */
    suspend fun recover(): DeviceSession.State? = lock.withLock {
        try {
            val journal = store.readJournal() ?: return@withLock null
            if (journal.pending == null) return@withLock null
            resolve(journal)
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (failure: Exception) {
            recovery("Rotation journal unavailable. Revoke this device from the parent account before re-pairing.")
        }
    }

    /** Revoking either active generation prevents an in-flight rotation resurrecting it. */
    suspend fun disconnectPending(): DeviceSession.State? = lock.withLock {
        try {
            val journal = store.readJournal() ?: return@withLock null
            val pending = journal.pending ?: return@withLock null
            val revoked = disconnectCandidate(pending) || disconnectCandidate(journal.current) || disconnectCandidate(pending)
            if (!revoked) {
                return@withLock recovery("Neither staged credential is authorized. Keep recovery data and revoke this device from the parent account.")
            }
            store.clear()
            check(store.readJournal() == null) { "Rotation cleanup failed." }
            DeviceSession.State(message = "Device disconnected. Rotation credentials removed.")
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (failure: Exception) {
            recovery("Rotation disconnect is unconfirmed or cleanup failed. Keep recovery data; retry or revoke from the parent account.")
        }
    }

    private suspend fun disconnectCandidate(token: String): Boolean = try {
        api.disconnect(token)
        true
    } catch (revoked: DeviceUnauthorizedException) {
        false
    }

    private suspend fun resolve(journal: CredentialJournal): DeviceSession.State {
        val pending = requireNotNull(journal.pending)
        val device = try {
            api.status(pending)
        } catch (revoked: DeviceUnauthorizedException) {
            return try {
                api.status(journal.current)
                recovery("Rotation is pending. Retry rotation with the saved candidate, or disconnect this device.")
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (oldRevoked: DeviceUnauthorizedException) {
                recovery("Both rotation credentials are rejected. Keep recovery data; retry or revoke from the parent account.")
            } catch (offline: Exception) {
                recovery("Cannot resolve rotation while offline. Keep recovery data and retry verification.")
            }
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (offline: Exception) {
            return recovery("Cannot verify the replacement credential. Keep recovery data and retry verification.")
        }
        store.promote(journal.current, pending)
        val promoted = store.readJournal()
        check(promoted?.current == pending && promoted.pending == null) { "Rotation promotion read-back failed." }
        return DeviceSession.State(DeviceSession.Phase.CONNECTED, device, "Replacement credential verified and saved. No monitoring is active.")
    }

    private fun recovery(message: String) = DeviceSession.State(DeviceSession.Phase.ERROR, message = message)
}