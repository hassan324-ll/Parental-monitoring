package com.familyguard.child
import android.os.Bundle
import android.content.Intent
import android.provider.Settings
import android.net.Uri
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.compose.ui.platform.LocalContext
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.compose.*
import com.familyguard.child.ui.CompanionViewModel
import com.familyguard.child.ui.PairingViewModel
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.text.input.KeyboardCapitalization
class MainActivity:ComponentActivity(){override fun onCreate(savedInstanceState:Bundle?){super.onCreate(savedInstanceState);setContent{MaterialTheme{CompanionApp()}}}}
@Composable fun CompanionApp(){
 val nav=rememberNavController()
 val pages=listOf("Welcome","Device Pairing","Monitoring Disclosure","Permission Overview","Connected Device Home","Activity Sharing Settings","Location Sharing Settings","Screen Sharing Requests","Privacy and Help","Connection Settings")
 val pairing:PairingViewModel=viewModel()
 val pairingState by pairing.state.collectAsStateWithLifecycle()
 val model:CompanionViewModel=viewModel()
 val status by model.status.collectAsStateWithLifecycle()
 val context=LocalContext.current
 Surface(Modifier.fillMaxSize()){Column(Modifier.padding(24.dp).verticalScroll(rememberScrollState())){
 Text("FamilyGuard Companion",style=MaterialTheme.typography.headlineMedium)
 Text("Visible, permission-based family safety",style=MaterialTheme.typography.bodyLarge)
 HorizontalDivider(Modifier.padding(vertical=16.dp))
 NavHost(navController=nav,startDestination="0",modifier=Modifier.fillMaxWidth()){
 pages.forEachIndexed{index,title->composable(index.toString()){
 Column(verticalArrangement=Arrangement.spacedBy(12.dp)){
 Text(title,style=MaterialTheme.typography.headlineSmall)
 Text(when(index){
 2->"Future sharing requires your knowledge and permission. No private messages or keystrokes are collected."
 3->"No monitoring permissions are requested in this version. Review or revoke system permissions below."
 5->"Activity sharing is not implemented. Nothing is being collected."
 6->"Location sharing is not implemented. GPS permission has not been requested."
 7->"No capture is active. Every future session requires fresh Android approval and a visible notification."
 8->"You control sharing. Revocation must stop collection and end active sessions. Pairing and deletion controls will arrive in a later phase."
 9->"Emulator API: "+BuildConfig.API_BASE_URL+". Release API must be configured before distribution."
 else->"Foundation preview. This device is not paired. No monitoring is active."
 })
 if(index==1){
 OutlinedTextField(value=pairingState.code,onValueChange=pairing::updateCode,label={Text("32-character invitation code")},keyboardOptions=KeyboardOptions(capitalization=KeyboardCapitalization.Characters),singleLine=true)
 Button(onClick=pairing::preview,enabled=!pairingState.busy && pairingState.code.length==32){Text("Review invitation")}
 if(pairingState.preview!=null){
 Text("Family: "+pairingState.preview!!.familyName)
 Text("Child: "+pairingState.preview!!.childName)
 Text("Pairing would allow this device to identify itself to the family. It does not enable monitoring permissions.")
 Button(onClick=pairing::approve,enabled=false){Text("Approve pairing (coming soon)")}
 Button(onClick=pairing::cancel){Text("Cancel")}
 }
 if(pairingState.message.isNotBlank()) Text(pairingState.message)
 }
 if(index==3)Button(onClick={context.startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS,Uri.parse("package:"+context.packageName)))}){Text("Review Android permissions")}
 if(index==4||index==9){Text(status);Button(onClick=model::checkHealth){Text("Check API connection")}}
 }
 }}
 }
 HorizontalDivider(Modifier.padding(vertical=16.dp))
 pages.forEachIndexed{index,title->TextButton(onClick={nav.navigate(index.toString()){launchSingleTop=true;popUpTo("0")}}){Text(title)}}
 }}
}
