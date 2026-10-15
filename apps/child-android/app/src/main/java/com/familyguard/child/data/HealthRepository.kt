package com.familyguard.child.data
import com.familyguard.child.BuildConfig
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import retrofit2.http.GET
data class HealthResponse(val status:String)
interface HealthApi{@GET("health") suspend fun health():HealthResponse}
class HealthRepository{ suspend fun check():String { require(BuildConfig.API_BASE_URL.isNotBlank()){ "Release API is not configured" };val api=Retrofit.Builder().baseUrl(BuildConfig.API_BASE_URL).addConverterFactory(GsonConverterFactory.create()).build().create(HealthApi::class.java);return api.health().status }}
