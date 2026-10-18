package com.familyguard.child.data

import javax.crypto.KeyGenerator
import org.junit.Assert.*
import org.junit.Test

class CredentialCipherTest {
    private fun key() = KeyGenerator.getInstance("AES").apply { init(256) }.generateKey()

    @Test fun restoresCredentialWithTheSameKeyAndUsesFreshNonce() {
        val key = key()
        val token = "synthetic-device-credential"
        val first = CredentialCipher.encrypt(token, key)
        val second = CredentialCipher.encrypt(token, key)
        assertEquals(token, CredentialCipher.decrypt(first, key))
        assertFalse(first.contentEquals(second))
        assertFalse(String(first, Charsets.ISO_8859_1).contains(token))
    }

    @Test fun rejectsTamperedCiphertextAndNonce() {
        val key = key()
        val original = CredentialCipher.encrypt("synthetic-token", key)
        for (index in listOf(1, original.lastIndex)) {
            val changed = original.copyOf()
            changed[index] = (changed[index].toInt() xor 1).toByte()
            assertThrows(Exception::class.java) { CredentialCipher.decrypt(changed, key) }
        }
    }

    @Test fun rejectsWrongKeyUnsupportedVersionAndTruncatedData() {
        val encrypted = CredentialCipher.encrypt("synthetic-token", key())
        assertThrows(Exception::class.java) { CredentialCipher.decrypt(encrypted, key()) }
        val unknownVersion = encrypted.copyOf().apply { this[0] = 2 }
        assertThrows(IllegalArgumentException::class.java) { CredentialCipher.decrypt(unknownVersion, key()) }
        assertThrows(IllegalArgumentException::class.java) { CredentialCipher.decrypt(byteArrayOf(1), key()) }
    }

    @Test fun boundsCredentialAndEnvelopeSizes() {
        val key = key()
        assertThrows(IllegalArgumentException::class.java) { CredentialCipher.encrypt("", key) }
        assertThrows(IllegalArgumentException::class.java) { CredentialCipher.encrypt("x".repeat(4097), key) }
        assertThrows(IllegalArgumentException::class.java) { CredentialCipher.decrypt(ByteArray(8193), key) }
    }
}