package com.familyguard.child.data

import android.content.Context
import android.content.ContextWrapper
import android.util.AtomicFile
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import java.io.File
import java.security.KeyStore
import java.util.UUID
import javax.crypto.SecretKey
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

/** Every test uses a unique directory and alias; never clears the app's real device credential. */
@RunWith(AndroidJUnit4::class)
class KeystoreCredentialStoreInstrumentedTest {
    private val old = "a".repeat(64)
    private val next = "b".repeat(64)
    private lateinit var testRoot: File
    private lateinit var alias: String
    private lateinit var context: Context
    private lateinit var store: KeystoreDeviceCredentialStore
    private val ciphertext get() = File(testRoot, "device-credential.v1")

    @Before fun setUp() {
        val application = ApplicationProvider.getApplicationContext<Context>()
        val namespace = UUID.randomUUID().toString()
        testRoot = File(application.noBackupFilesDir, "fg-instrumentation-$namespace")
        check(testRoot.mkdirs())
        alias = "familyguard.instrumentation.$namespace"
        context = object : ContextWrapper(application) {
            override fun getApplicationContext(): Context = this
            override fun getNoBackupFilesDir(): File = testRoot
        }
        store = KeystoreDeviceCredentialStore(context, alias)
    }

    @After fun tearDown() = runBlocking {
        if (::store.isInitialized) store.clear()
        if (::testRoot.isInitialized) {
            val application = ApplicationProvider.getApplicationContext<Context>()
            check(requireNotNull(testRoot.parentFile).canonicalFile == application.noBackupFilesDir.canonicalFile)
            check(testRoot.name.startsWith("fg-instrumentation-"))
            check(testRoot.deleteRecursively())
        }
    }

    @Test fun encryptedSaveUsesNoBackupStorageAndDoesNotWritePlaintext() = runBlocking {
        store.prepare()
        store.save(old)
        assertEquals(old, store.read())
        val text = String(ciphertext.readBytes(), Charsets.ISO_8859_1)
        assertFalse(text.contains(old))
        assertTrue(testRoot.canonicalPath.startsWith(ApplicationProvider.getApplicationContext<Context>().noBackupFilesDir.canonicalPath))
        assertFalse(File(testRoot, "credential-readiness.probe").exists())
    }

    @Test fun reconstructedStoreReadsStagesAndPromotesBothGenerations() = runBlocking {
        store.save(old)
        store.stage(old, next)
        val reconstructed = KeystoreDeviceCredentialStore(context, alias)
        assertEquals(old, reconstructed.readJournal()?.current)
        assertEquals(next, reconstructed.readJournal()?.pending)
        reconstructed.promote(old, next)
        val restored = KeystoreDeviceCredentialStore(context, alias).readJournal()
        assertEquals(next, restored?.current)
        assertNull(restored?.pending)
    }

    @Test fun legacyEncryptedTokenCanUpgradeToJournal() = runBlocking {
        store.save(old)
        val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        val key = keyStore.getKey(alias, null) as SecretKey
        ciphertext.writeBytes(CredentialCipher.encrypt(old, key))
        assertEquals(old, store.readJournal()?.current)
        assertNull(store.readJournal()?.pending)
        store.stage(old, next)
        store.promote(old, next)
        assertEquals(next, store.read())
    }

    @Test fun missingKeyFailsClosedWithoutGeneratingReplacementKey() = runBlocking {
        store.save(old)
        val original = ciphertext.readBytes()
        val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        keyStore.deleteEntry(alias)
        expectFailure { store.read() }
        assertFalse(keyStore.containsAlias(alias))
        assertArrayEquals(original, ciphertext.readBytes())
    }

    @Test fun tamperedCiphertextFailsClosedWithoutReplacingIt() = runBlocking {
        store.save(old)
        val corrupted = ciphertext.readBytes().apply { this[lastIndex] = (this[lastIndex].toInt() xor 1).toByte() }
        ciphertext.writeBytes(corrupted)
        expectFailure { store.read() }
        assertArrayEquals(corrupted, ciphertext.readBytes())
    }

    @Test fun stagingAndPromotionRejectStaleGenerationsAndOverwrite() = runBlocking {
        store.save(old)
        expectFailure { store.stage("c".repeat(64), next) }
        store.stage(old, next)
        expectFailure { store.save("c".repeat(64)) }
        expectFailure { store.promote(old, "c".repeat(64)) }
        assertEquals(old, store.readJournal()?.current)
        assertEquals(next, store.readJournal()?.pending)
    }

    @Test fun explicitClearRemovesOnlyTestJournalAndKey() = runBlocking {
        store.save(old)
        store.stage(old, next)
        store.clear()
        assertNull(store.readJournal())
        assertFalse(KeyStore.getInstance("AndroidKeyStore").apply { load(null) }.containsAlias(alias))
        for (suffix in listOf("", ".bak", ".new")) assertFalse(File(ciphertext.path + suffix).exists())
    }

    @Test fun abortedAtomicWriteLeavesThePreviousJournalReadable() = runBlocking {
        store.save(old)
        store.stage(old, next)
        val atomic = AtomicFile(ciphertext)
        val output = atomic.startWrite()
        output.write(byteArrayOf(1, 2, 3))
        atomic.failWrite(output)
        val restored = KeystoreDeviceCredentialStore(context, alias).readJournal()
        assertEquals(old, restored?.current)
        assertEquals(next, restored?.pending)
    }

    @Test fun pendingApprovalSurvivesReconstructionAndCannotBeOverwrittenOrRotated() = runBlocking {
        store.stageApproval(old)
        val reconstructed = KeystoreDeviceCredentialStore(context, alias)
        assertTrue(reconstructed.readJournal()!!.approvalPending)
        expectFailure { reconstructed.save(old) }
        expectFailure { reconstructed.stage(old, next) }
        expectFailure { reconstructed.promote(old, next) }
        expectFailure { reconstructed.stageApproval(next) }
        reconstructed.confirmApproval(old)
        assertFalse(KeystoreDeviceCredentialStore(context, alias).readJournal()!!.approvalPending)
        assertEquals(old, reconstructed.read())
    }

    private suspend fun expectFailure(action: suspend () -> Unit) {
        var rejected = false
        try { action() } catch (failure: IllegalStateException) { rejected = true }
        assertTrue("Invalid or unavailable credential state must fail closed.", rejected)
    }
}