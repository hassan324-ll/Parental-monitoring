package com.familyguard.child.data

import kotlinx.coroutines.CancellationException

interface StagedPairingApprovalApi {
    suspend fun approveStaged(code: String, deviceName: String, androidVersion: String, credentialHash: String)
}

/** DeviceSession holds the operation mutex. No invitation or credential enters UI state. */
internal class ApprovalHandoff(
    private val store: ApprovingDeviceCredentialStore,
    private val api: CompanionSessionApi,
    private val pairing: StagedPairingApprovalApi,
    private val generate: () -> String
) {
    suspend fun approve(code: String, deviceName: String, androidVersion: String): DeviceSession.State {
        val token = try {
            check(store.readJournal() == null)
            store.prepare()
            val candidate = generate()
            store.stageApproval(candidate)
            val journal = store.readJournal()
            check(journal?.current == candidate && journal.approvalPending && journal.pending == null)
            candidate
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (failure: Exception) {
            return error("Approval was not sent because secure staging could not be verified. Check storage and any retained recovery data.")
        }
        try {
            pairing.approveStaged(code, deviceName, androidVersion, CredentialTokens.hash(token))
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (uncertain: Exception) {
            // A lost response may follow a successful commit; verify using the SAME stored candidate.
        }
        return resolve(token)
    }

    suspend fun recover(): DeviceSession.State? = try {
        val journal = store.readJournal()
        if (journal?.approvalPending == true) resolve(journal.current) else null
    } catch (cancelled: CancellationException) {
        throw cancelled
    } catch (failure: Exception) {
        error("Approval recovery data unavailable. Review/revoke this device from the parent account before re-pairing.")
    }

    suspend fun disconnectPending(): DeviceSession.State? {
        try {
            val journal = store.readJournal() ?: return null
            if (!journal.approvalPending) return null
            try {
                api.disconnect(journal.current)
            } catch (rejected: DeviceUnauthorizedException) {
                return error("Pending approval is not authorized yet or was revoked. Keep recovery data and review/revoke this device from the parent account; a request may still be in flight.")
            }
            store.clear()
            check(store.readJournal() == null)
            return DeviceSession.State(message = "Server disconnect confirmed. Pending approval data removed.")
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (failure: Exception) {
            return error("Pending approval disconnect or cleanup is unconfirmed. Keep recovery data; retry or revoke from the parent account.")
        }
    }

    private suspend fun resolve(token: String): DeviceSession.State {
        val device = try {
            api.status(token)
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (rejected: DeviceUnauthorizedException) {
            return error("Pending approval is not authorized yet or was revoked. Recovery data is retained; retry verification or review/revoke from the parent account. Approval will not be resent.")
        } catch (offline: Exception) {
            return DeviceSession.State(DeviceSession.Phase.OFFLINE, message = "Pending approval saved securely; authorization is unverified. Retry verification without resending approval.")
        }
        return try {
            store.confirmApproval(token)
            val journal = store.readJournal()
            check(journal?.current == token && !journal.approvalPending && journal.pending == null)
            DeviceSession.State(DeviceSession.Phase.CONNECTED, device, "Pairing saved and device authorization verified. No monitoring is active.")
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (failure: Exception) {
            error("Server authorization verified, but local approval confirmation failed. Keep recovery data and retry verification or disconnect.")
        }
    }

    private fun error(message: String) = DeviceSession.State(DeviceSession.Phase.ERROR, message = message)
}
