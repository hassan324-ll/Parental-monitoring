package com.familyguard.child.data

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.AtomicFile
import java.io.DataInputStream
import java.io.File
import java.security.KeyStore
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext

interface DeviceCredentialStore {
    suspend fun prepare()
    suspend fun save(token: String)
    suspend fun read(): String?
    suspend fun clear()
}

/** An atomic encrypted journal keeps both generations through a rotation handoff. */
class KeystoreDeviceCredentialStore(context: Context) : RotatingDeviceCredentialStore {
    private val file = AtomicFile(File(context.applicationContext.noBackupFilesDir, "device-credential.v1"))

    override suspend fun prepare(): Unit = withContext(Dispatchers.IO) {
        lock.withLock {
            val probe = AtomicFile(File(file.baseFile.parentFile, "credential-readiness.probe"))
            try {
                val key = key(create = true)
                val encrypted = CredentialCipher.encrypt("storage-readiness", key)
                writeAtomic(probe, encrypted)
                val restored = probe.openRead().use { input ->
                    val bytes = ByteArray(encrypted.size)
                    DataInputStream(input).readFully(bytes)
                    CredentialCipher.decrypt(bytes, key)
                }
                check(restored == "storage-readiness") { "Secure storage is unavailable." }
            } finally {
                probe.delete()
            }
        }
    }

    override suspend fun save(token: String): Unit = withContext(Dispatchers.IO) {
        lock.withLock {
            val existing = readLocked()
            check(existing == null || (existing.current == token && existing.pending == null)) {
                "Cannot overwrite an existing credential journal."
            }
            writeLocked(CredentialJournal(token))
        }
    }

    override suspend fun read(): String? = readJournal()?.current

    override suspend fun readJournal(): CredentialJournal? = withContext(Dispatchers.IO) {
        lock.withLock { readLocked() }
    }

    override suspend fun stage(current: String, pending: String): Unit = withContext(Dispatchers.IO) {
        lock.withLock {
            val existing = readLocked()
            check(existing?.current == current && (existing.pending == null || existing.pending == pending)) {
                "Credential journal changed before staging."
            }
            writeLocked(CredentialJournal(current, pending))
        }
    }

    override suspend fun promote(current: String, pending: String): Unit = withContext(Dispatchers.IO) {
        lock.withLock {
            val existing = readLocked()
            check(existing?.current == current && existing.pending == pending) {
                "Credential journal changed before promotion."
            }
            writeLocked(CredentialJournal(pending))
        }
    }

    override suspend fun clear(): Unit = withContext(Dispatchers.IO) {
        lock.withLock {
            file.delete()
            check(listOf("", ".bak", ".new").none { File(file.baseFile.path + it).exists() }) {
                "Could not clear device credential."
            }
            val store = KeyStore.getInstance(PROVIDER).apply { load(null) }
            if (store.containsAlias(ALIAS)) store.deleteEntry(ALIAS)
        }
    }

    private fun readLocked(): CredentialJournal? {
        if (!file.baseFile.exists() && !File(file.baseFile.path + ".bak").exists()) return null
        try {
            val encrypted = file.openRead().use { input ->
                val buffer = ByteArray(8193)
                var size = 0
                while (size < buffer.size) {
                    val count = input.read(buffer, size, buffer.size - size)
                    if (count < 0) break
                    size += count
                }
                check(size <= 8192) { "Invalid encrypted credential." }
                buffer.copyOf(size)
            }
            return CredentialJournalCodec.decode(CredentialCipher.decrypt(encrypted, key(create = false)))
        } catch (failure: Exception) {
            throw IllegalStateException("Stored credential journal unavailable. Revoke from the parent account before re-pairing.")
        }
    }

    private fun writeLocked(journal: CredentialJournal) {
        val encrypted = CredentialCipher.encrypt(CredentialJournalCodec.encode(journal), key(create = true))
        writeAtomic(file, encrypted)
        val restored = readLocked()
        check(restored?.current == journal.current && restored.pending == journal.pending) {
            "Credential journal read-back failed."
        }
    }

    private fun writeAtomic(target: AtomicFile, encrypted: ByteArray) {
        val output = target.startWrite()
        try {
            output.write(encrypted)
            target.finishWrite(output)
        } catch (failure: Exception) {
            target.failWrite(output)
            throw IllegalStateException("Could not persist credential journal.")
        }
    }

    private fun key(create: Boolean): SecretKey {
        val store = KeyStore.getInstance(PROVIDER).apply { load(null) }
        val existing = store.getKey(ALIAS, null)
        if (existing is SecretKey) return existing
        check(create) { "Device credential key unavailable." }
        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, PROVIDER)
        generator.init(
            KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setKeySize(256)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setRandomizedEncryptionRequired(true)
                .build()
        )
        return generator.generateKey()
    }

    private companion object {
        const val PROVIDER = "AndroidKeyStore"
        const val ALIAS = "familyguard.device-credential.v1"
        val lock = Mutex()
    }
}