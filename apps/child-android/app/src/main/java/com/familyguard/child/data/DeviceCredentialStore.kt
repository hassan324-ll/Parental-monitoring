package com.familyguard.child.data

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.AtomicFile
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

/** App-private ciphertext excluded from backup. Requires a non-exportable Android Keystore key. */
class KeystoreDeviceCredentialStore(context: Context) : DeviceCredentialStore {
    private val file = AtomicFile(File(context.applicationContext.noBackupFilesDir, "device-credential.v1"))

    override suspend fun prepare(): Unit = withContext(Dispatchers.IO) {
        lock.withLock {
            val probe = AtomicFile(File(file.baseFile.parentFile, "credential-readiness.probe"))
            try {
                val key = key(create = true)
                val encrypted = CredentialCipher.encrypt("storage-readiness", key)
                val output = probe.startWrite()
                try {
                    output.write(encrypted)
                    probe.finishWrite(output)
                } catch (failure: Exception) {
                    probe.failWrite(output)
                    throw failure
                }
                val restored = probe.openRead().use { input ->
                    val bytes = ByteArray(encrypted.size)
                    java.io.DataInputStream(input).readFully(bytes)
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
            val encrypted = CredentialCipher.encrypt(token, key(create = true))
            val output = file.startWrite()
            try {
                output.write(encrypted)
                file.finishWrite(output)
            } catch (failure: Exception) {
                file.failWrite(output)
                throw IllegalStateException("Could not persist device credential.")
            }
        }
    }

    override suspend fun read(): String? = withContext(Dispatchers.IO) {
        lock.withLock {
            if (!file.baseFile.exists() && !File(file.baseFile.path + ".bak").exists()) {
                return@withLock null
            }
            try {
                val encrypted = file.openRead().use { input ->
                    val buffer = ByteArray(8193)
                    var size = 0
                    while (size < buffer.size) {
                        val count = input.read(buffer, size, buffer.size - size)
                        if (count < 0) break
                        size += count
                    }
                    val bytes = buffer.copyOf(size)
                    check(bytes.size <= 8192) { "Invalid encrypted credential." }
                    bytes
                }
                CredentialCipher.decrypt(encrypted, key(create = false))
            } catch (failure: Exception) {
                // Never silently replace a missing/invalid key or treat corrupted state as paired.
                throw IllegalStateException("Stored device credential is unavailable. Re-pairing is required.")
            }
        }
    }

    override suspend fun clear(): Unit = withContext(Dispatchers.IO) {
        lock.withLock {
            file.delete()
            check(!file.baseFile.exists()) { "Could not clear device credential." }
            val store = KeyStore.getInstance(PROVIDER).apply { load(null) }
            if (store.containsAlias(ALIAS)) store.deleteEntry(ALIAS)
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