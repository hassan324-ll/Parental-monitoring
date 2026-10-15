package com.familyguard.child.ui
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.familyguard.child.data.HealthRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.CancellationException
class CompanionViewModel:ViewModel(){private val repository=HealthRepository();private val state=MutableStateFlow("Connection not checked");val status=state.asStateFlow();fun checkHealth(){viewModelScope.launch{state.value="Checking…";try{state.value=if(repository.check()=="ok")"API available" else "Unexpected API response"}catch(e:CancellationException){throw e}catch(e:Exception){state.value="API unavailable. Check your local connection."}}}}
