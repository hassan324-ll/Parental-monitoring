package com.familyguard.child.data

import java.nio.ByteBuffer
import javax.crypto.Cipher
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/** Versioned authenticated envelope; plaintext and keys are never serialized together. */
internal object CredentialCipher {
    private val context = "FamilyGuard/device-credential/v1".toByteArray(Charsets.UTF_8)
    private const val VERSION: Byte = 1
    private const val IV_SIZE = 12
    private const val MAX_TOKEN_BYTES = 4096

    fun encrypt(token: String, key: SecretKey): ByteArray {
        val plaintext = token.toByteArray(Charsets.UTF_8)
        require(plaintext.isNotEmpty() && plaintext.size <= MAX_TOKEN_BYTES) {
            "Invalid device credential length."
        }
        try {
            val cipher = Cipher.getInstance("AES/GCM/NoPadding")
            cipher.init(Cipher.ENCRYPT_MODE, key)
            cipher.updateAAD(context)
            check(cipher.iv.size == IV_SIZE) { "Unsupported credential encryption parameters." }
            val encrypted = cipher.doFinal(plaintext)
            return ByteBuffer.allocate(1 + IV_SIZE + encrypted.size)
                .put(VERSION).put(cipher.iv).put(encrypted).array()
        } finally {
            plaintext.fill(0)
        }
    }

    fun decrypt(envelope: ByteArray, key: SecretKey): String {
        require(envelope.size in (1 + IV_SIZE + 16 + 1)..(1 + IV_SIZE + 16 + MAX_TOKEN_BYTES)) {
            "Invalid encrypted credential."
        }
        require(envelope[0] == VERSION) { "Unsupported credential format." }
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.DECRYPT_MODE, key, GCMParameterSpec(128, envelope.copyOfRange(1, 1 + IV_SIZE)))
        cipher.updateAAD(context)
        val plaintext = cipher.doFinal(envelope, 1 + IV_SIZE, envelope.size - 1 - IV_SIZE)
        return try {
            String(plaintext, Charsets.UTF_8)
        } finally {
            plaintext.fill(0)
        }
    }
}