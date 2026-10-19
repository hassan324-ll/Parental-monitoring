package com.familyguard.child.data

/** Secret-bearing values must never be data classes with generated diagnostic output. */
class CredentialJournal(val current: String, val pending: String? = null) {
    init {
        require(current.matches(Regex("[a-f0-9]{64}"))) { "Invalid credential journal." }
        require(pending == null || (pending.matches(Regex("[a-f0-9]{64}")) && pending != current)) {
            "Invalid pending credential."
        }
    }
    override fun toString() = "CredentialJournal(redacted)"
}

internal object CredentialJournalCodec {
    fun encode(journal: CredentialJournal) = "FG2\n${journal.current}\n${journal.pending.orEmpty()}"
    fun decode(value: String): CredentialJournal {
        // Upgrade existing single-token encrypted files without losing the current session.
        if (value.matches(Regex("[a-f0-9]{64}"))) return CredentialJournal(value)
        val parts = value.split('\n')
        require(parts.size == 3 && parts[0] == "FG2") { "Unsupported credential journal." }
        return CredentialJournal(parts[1], parts[2].ifEmpty { null })
    }
}

interface RotatingDeviceCredentialStore : DeviceCredentialStore {
    suspend fun readJournal(): CredentialJournal?
    suspend fun stage(current: String, pending: String)
    suspend fun promote(current: String, pending: String)
}