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
import com.familyguard.child.ui.DeviceSessionViewModel
import com.familyguard.child.data.DeviceSession
import com.familyguard.child.data.SharingFeature
import com.familyguard.child.ui.SharingSettings
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
 val deviceSession:DeviceSessionViewModel=viewModel()
 val sessionState by deviceSession.state.collectAsStateWithLifecycle()
 val sharingState by deviceSession.sharing.collectAsStateWithLifecycle()
 var confirmDisconnect by remember { mutableStateOf(false) }
 var confirmRotation by remember { mutableStateOf(false) }
 var confirmLocalReset by remember { mutableStateOf(false) }
 var parentRevocationAcknowledged by remember { mutableStateOf(false) }
 val sessionBusy=sharingState.busy || sessionState.phase==DeviceSession.Phase.CHECKING || sessionState.phase==DeviceSession.Phase.DISCONNECTING || sessionState.phase==DeviceSession.Phase.PAIRING || sessionState.phase==DeviceSession.Phase.ROTATING
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
 5->"Activity preference and deletion controls. Collection is unavailable."
 6->"Location preference and deletion controls. No GPS permission is requested."
 7->"No capture is active. Every future session requires fresh Android approval and a visible notification."
 8->"You control sharing. Revocation must stop collection and end active sessions. Device status and disconnect are available in Connected Device Home. Pair only after reviewing the invitation and choosing Approve pairing on this device."
 9->"Emulator API: "+BuildConfig.API_BASE_URL+". Release API must be configured before distribution."
 else->sessionState.message
 })
 if(index==1){
 OutlinedTextField(value=pairingState.code,onValueChange=pairing::updateCode,enabled=!pairingState.busy,label={Text("32-character invitation code")},keyboardOptions=KeyboardOptions(capitalization=KeyboardCapitalization.Characters),singleLine=true)
 Button(onClick=pairing::preview,enabled=!pairingState.busy && pairingState.code.length==32){Text("Review invitation")}
 if(pairingState.preview!=null){
 Text("Family: "+pairingState.preview!!.familyName)
 Text("Child: "+pairingState.preview!!.childName)
 Text("Pairing would allow this device to identify itself to the family. It does not enable monitoring permissions.")
 Button(onClick={pairing.approve { code -> deviceSession.approve(code) }},enabled=!pairingState.busy && !sessionBusy && sessionState.phase==DeviceSession.Phase.UNPAIRED){Text("Approve pairing on this device")}

 }
 Button(onClick=pairing::cancel,enabled=!pairingState.approving && (pairingState.busy || pairingState.code.isNotBlank() || pairingState.preview!=null)){Text("Cancel")}
 if(pairingState.message.isNotBlank()) Text(pairingState.message)
 }
 if(index==5||index==6) SharingSettings(if(index==5) SharingFeature.USAGE else SharingFeature.LOCATION, sharingState, sessionState.phase==DeviceSession.Phase.CONNECTED, sessionBusy, deviceSession::refreshSharing, deviceSession::decideSharing)
 if(index==3)Button(onClick={context.startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS,Uri.parse("package:"+context.packageName)))}){Text("Review Android permissions")}
 if(index==4||index==9){
 Text(sessionState.message)
 sessionState.device?.let { Text("Device: "+it.name) }
 Button(onClick=deviceSession::refresh,enabled=!sessionBusy){Text("Verify device authorization")}
 Button(onClick={confirmDisconnect=true},enabled=!sessionBusy && sessionState.phase!=DeviceSession.Phase.UNPAIRED){Text("Disconnect device")}
 Button(onClick={confirmRotation=true},enabled=!sessionBusy && sessionState.phase!=DeviceSession.Phase.UNPAIRED){Text("Rotate or recover credential")}
 if(sessionState.canForgetLocalPairing){
 TextButton(onClick={parentRevocationAcknowledged=false;confirmLocalReset=true},enabled=!sessionBusy){Text("Remove local pairing recovery data")}
 }
 Text(status);Button(onClick=model::checkHealth){Text("Check API connection")}
 }
 if(confirmLocalReset){
 AlertDialog(onDismissRequest={confirmLocalReset=false;parentRevocationAcknowledged=false},title={Text("Remove local pairing data?")},text={Column(verticalArrangement=Arrangement.spacedBy(12.dp)){
 Text("Try Disconnect first. If recovery cannot finish, ask the parent to revoke this device in Connected Devices before removing local data. Erasing this device's credentials does not revoke server access and cannot undo an in-flight server request. Recovery credentials will be lost.")
 Row { Checkbox(checked=parentRevocationAcknowledged,onCheckedChange={parentRevocationAcknowledged=it});Text("I have revoked this device from the parent account.") }
 Text("The app cannot verify that acknowledgment. Cancel to keep recovery data.")
 }},confirmButton={TextButton(onClick={confirmLocalReset=false;deviceSession.forgetLocalPairing(parentRevocationAcknowledged);parentRevocationAcknowledged=false},enabled=parentRevocationAcknowledged && sessionState.canForgetLocalPairing && !sessionBusy){Text("Remove local data")}},dismissButton={TextButton(onClick={confirmLocalReset=false;parentRevocationAcknowledged=false}){Text("Cancel")}})
 }
 if(confirmRotation){
 AlertDialog(onDismissRequest={confirmRotation=false},title={Text("Rotate device credential?")},text={Text("The replacement is saved securely before the server request. Pending recovery is preserved if the connection fails. This grants no monitoring permissions.")},confirmButton={TextButton(onClick={confirmRotation=false;deviceSession.rotate()}){Text("Rotate or recover")}},dismissButton={TextButton(onClick={confirmRotation=false}){Text("Cancel")}})
 }
 if(confirmDisconnect){
 AlertDialog(onDismissRequest={confirmDisconnect=false},title={Text("Disconnect this device?")},text={Text("This requests server revocation and removes local credentials after confirmation. If offline, retry or revoke from the parent account.")},confirmButton={TextButton(onClick={confirmDisconnect=false;deviceSession.disconnect()}){Text("Disconnect")}},dismissButton={TextButton(onClick={confirmDisconnect=false}){Text("Cancel")}})
 }
 }
 }}
 }
 HorizontalDivider(Modifier.padding(vertical=16.dp))
 pages.forEachIndexed{index,title->TextButton(onClick={nav.navigate(index.toString()){launchSingleTop=true;popUpTo("0")}}){Text(title)}}
 }}
}
