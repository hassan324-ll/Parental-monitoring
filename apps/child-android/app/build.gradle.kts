plugins { id("com.android.application"); id("org.jetbrains.kotlin.android"); id("org.jetbrains.kotlin.plugin.compose") }
android {
 namespace="com.familyguard.child"
 compileSdk=36
 defaultConfig { applicationId="com.familyguard.child"; minSdk=26; targetSdk=36; versionCode=1; versionName="0.1.0"; testInstrumentationRunner="androidx.test.runner.AndroidJUnitRunner" }
 buildFeatures { compose=true; buildConfig=true }
 buildTypes {
 debug { applicationIdSuffix=".debug"; manifestPlaceholders["cleartext"]="true"; buildConfigField("String","API_BASE_URL","\"http://10.0.2.2:8000/api/v1/\"") }
 release { isMinifyEnabled=false; manifestPlaceholders["cleartext"]="false"; buildConfigField("String","API_BASE_URL","\"\"") }
 }
 compileOptions { sourceCompatibility=JavaVersion.VERSION_17; targetCompatibility=JavaVersion.VERSION_17 }
 kotlinOptions { jvmTarget="17" }
}
dependencies {
 androidTestImplementation("androidx.test:core:1.7.0")
 androidTestImplementation("androidx.test:runner:1.7.0")
 androidTestImplementation("androidx.test.ext:junit:1.3.0")
 testImplementation("junit:junit:4.13.2")
 testImplementation("org.jetbrains.kotlinx:kotlinx-coroutines-test:1.10.2")
 implementation(platform("androidx.compose:compose-bom:2025.10.01"))
 implementation("androidx.activity:activity-compose:1.11.0")
 implementation("androidx.compose.material3:material3")
 implementation("androidx.compose.ui:ui-tooling-preview")
 debugImplementation("androidx.compose.ui:ui-tooling")
 implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.9.4")
 implementation("androidx.lifecycle:lifecycle-runtime-compose:2.9.4")
 implementation("androidx.navigation:navigation-compose:2.9.5")
 implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.10.2")
 implementation("com.squareup.retrofit2:retrofit:3.0.0")
 implementation("com.squareup.retrofit2:converter-gson:3.0.0")
 implementation("com.squareup.okhttp3:okhttp:4.12.0")
 implementation("androidx.work:work-runtime-ktx:2.10.5")
 implementation(platform("com.google.firebase:firebase-bom:34.4.0"))
 implementation("com.google.firebase:firebase-auth")
 implementation("com.google.firebase:firebase-messaging")
}
