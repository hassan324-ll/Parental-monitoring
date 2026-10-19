package com.familyguard.child.data

import javax.crypto.KeyGenerator
import org.junit.Assert.*
import org.junit.Test

class CredentialJournalTest {
    private val old = "a".repeat(64)
    private val next = "b".repeat(64)

    @Test fun readsLegacyAndRoundTripsCurrentOnlyJournal() {
        assertEquals(old, CredentialJournalCodec.decode(old).current)
        val restored = CredentialJournalCodec.decode(CredentialJournalCodec.encode(CredentialJournal(old)))
        assertEquals(old, restored.current)
        assertNull(restored.pending)
    }

    @Test fun encryptedJournalRestoresBothGenerationsWithoutDiagnosticDisclosure() {
        val journal = CredentialJournal(old, next)
        val key = KeyGenerator.getInstance("AES").apply { init(256) }.generateKey()
        val encrypted = CredentialCipher.encrypt(CredentialJournalCodec.encode(journal), key)
        val restored = CredentialJournalCodec.decode(CredentialCipher.decrypt(encrypted, key))
        assertEquals(old, restored.current)
        assertEquals(next, restored.pending)
        assertFalse(journal.toString().contains(old))
        assertFalse(journal.toString().contains(next))
        assertFalse(String(encrypted, Charsets.ISO_8859_1).contains(old))
        assertFalse(String(encrypted, Charsets.ISO_8859_1).contains(next))
    }

    @Test fun rejectsMalformedUnknownAndDuplicateGenerationJournals() {
        for (invalid in listOf("", "FG3\n$old\n$next", "FG2\n$old", "FG2\n$old\n$next\nextra", "FG2\n$old\n$old")) {
            assertThrows(IllegalArgumentException::class.java) { CredentialJournalCodec.decode(invalid) }
        }
    }

    @Test fun generatesDistinct256BitLowercaseCredentials() {
        val first = CredentialTokens.generate()
        val second = CredentialTokens.generate()
        assertTrue(first.matches(Regex("[a-f0-9]{64}")))
        assertTrue(second.matches(Regex("[a-f0-9]{64}")))
        assertNotEquals(first, second)
    }

    @Test fun hashesTheUtf8HexTokenMatchingTheBackendContract() {
        assertEquals("ffe054fe7ae0cb6dc65c3af9b61d5209f439851db43d0ba5997337df154668eb", CredentialTokens.hash(old))
    }
}