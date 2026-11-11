package com.osmopad.app

import android.Manifest
import android.app.Activity
import android.bluetooth.BluetoothDevice
import android.content.Context
import android.content.pm.PackageManager
import android.hardware.input.InputManager
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.InputDevice
import android.view.KeyEvent
import android.view.MotionEvent
import android.widget.Button
import android.widget.CheckBox
import android.widget.LinearLayout
import android.widget.SeekBar
import android.widget.TextView
import android.widget.Toast
import kotlin.math.abs

class MainActivity : Activity(), GimbalBle.Listener, InputManager.InputDeviceListener {

    private lateinit var gimbal: GimbalBle
    private lateinit var inputManager: InputManager
    private val main = Handler(Looper.getMainLooper())
    private val prefs by lazy { getSharedPreferences("osmo_pad", Context.MODE_PRIVATE) }

    private lateinit var statusView: TextView
    private lateinit var padView: TextView
    private lateinit var batteryView: TextView
    private lateinit var liveView: TextView
    private lateinit var speedLabel: TextView
    private lateinit var speedBar: SeekBar
    private lateinit var devicesView: LinearLayout
    private lateinit var showAll: CheckBox
    private lateinit var invertPan: CheckBox
    private lateinit var invertTilt: CheckBox
    private lateinit var pwmMode: CheckBox
    private lateinit var swapAxes: CheckBox
    private lateinit var logMotion: CheckBox
    private lateinit var logView: TextView

    // Bieżący stan pada (aktualizowany ze zdarzeń wejścia).
    private var lx = 0f
    private var ly = 0f
    private var rx = 0f
    private var ry = 0f
    private var lt = 0f
    private var rt = 0f
    private var hatX = 0f
    private var hatY = 0f
    private var dpadX = 0f
    private var dpadY = 0f

    private var wasMoving = false
    private var connected = false
    private val seenDevices = mutableMapOf<String, Button>()
    private val logLines = ArrayDeque<String>()

    private val maxSpeed: Int get() = (speedBar.progress + 1) * 100

    companion object {
        private const val TICK_MS = 50L
        private const val PRECISION = 0.3f
        private const val DPAD_SPEED = 0.35f
        private const val REQ_PERMS = 1
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        statusView = findViewById(R.id.status)
        padView = findViewById(R.id.padStatus)
        batteryView = findViewById(R.id.battery)
        liveView = findViewById(R.id.live)
        speedLabel = findViewById(R.id.speedLabel)
        speedBar = findViewById(R.id.speed)
        devicesView = findViewById(R.id.devices)
        showAll = findViewById(R.id.showAll)
        invertPan = findViewById(R.id.invertPan)
        invertTilt = findViewById(R.id.invertTilt)
        pwmMode = findViewById(R.id.pwmMode)
        swapAxes = findViewById(R.id.swapAxes)
        logMotion = findViewById(R.id.logMotion)
        logView = findViewById(R.id.log)

        gimbal = GimbalBle(this, this)
        inputManager = getSystemService(Context.INPUT_SERVICE) as InputManager

        speedBar.progress = prefs.getInt("speed", 5)
        invertPan.isChecked = prefs.getBoolean("invertPan", false)
        invertTilt.isChecked = prefs.getBoolean("invertTilt", false)
        pwmMode.isChecked = prefs.getBoolean("pwm", false)
        swapAxes.isChecked = prefs.getBoolean("swapAxes", false)
        gimbal.swapPitchRoll = swapAxes.isChecked
        updateSpeedLabel()

        speedBar.setOnSeekBarChangeListener(object : SeekBar.OnSeekBarChangeListener {
            override fun onProgressChanged(s: SeekBar, p: Int, fromUser: Boolean) = updateSpeedLabel()
            override fun onStartTrackingTouch(s: SeekBar) {}
            override fun onStopTrackingTouch(s: SeekBar) = savePrefs()
        })
        invertPan.setOnCheckedChangeListener { _, _ -> savePrefs() }
        invertTilt.setOnCheckedChangeListener { _, _ -> savePrefs() }
        pwmMode.setOnCheckedChangeListener { _, _ ->
            savePrefs()
            gimbal.stop()
        }
        showAll.setOnCheckedChangeListener { _, _ -> clearDevices() }

        findViewById<Button>(R.id.scan).setOnClickListener { withPermissions { clearDevices(); gimbal.startScan() } }
        findViewById<Button>(R.id.reconnect).setOnClickListener { connectLast() }
        findViewById<Button>(R.id.disconnect).setOnClickListener { gimbal.disconnect() }
        findViewById<Button>(R.id.recenter).setOnClickListener { gimbal.recenter() }
        findViewById<Button>(R.id.stop).setOnClickListener { gimbal.stop() }
        findViewById<Button>(R.id.test1).setOnClickListener { slotTest(0) }
        findViewById<Button>(R.id.test2).setOnClickListener { slotTest(1) }
        findViewById<Button>(R.id.test3).setOnClickListener { slotTest(2) }
        swapAxes.setOnCheckedChangeListener { _, checked ->
            gimbal.swapPitchRoll = checked
            savePrefs()
        }
        findViewById<Button>(R.id.modeLock).setOnClickListener { gimbal.setMode(0) }
        findViewById<Button>(R.id.modeFollow).setOnClickListener { gimbal.setMode(1) }
        findViewById<Button>(R.id.modeFpv).setOnClickListener { gimbal.setMode(2) }

        updatePadStatus()
        withPermissions { }
    }

    override fun onResume() {
        super.onResume()
        inputManager.registerInputDeviceListener(this, main)
        updatePadStatus()
        main.post(tick)
    }

    override fun onPause() {
        super.onPause()
        inputManager.unregisterInputDeviceListener(this)
        main.removeCallbacks(tick)
        resetInput()
        gimbal.stop()
    }

    override fun onDestroy() {
        super.onDestroy()
        gimbal.shutdown()
    }

    private fun savePrefs() {
        prefs.edit()
            .putInt("speed", speedBar.progress)
            .putBoolean("invertPan", invertPan.isChecked)
            .putBoolean("invertTilt", invertTilt.isChecked)
            .putBoolean("pwm", pwmMode.isChecked)
            .putBoolean("swapAxes", swapAxes.isChecked)
            .apply()
    }

    private fun updateSpeedLabel() {
        speedLabel.text = "Prędkość maksymalna: ${maxSpeed / 10}°/s  (LB/RB na padzie)"
    }

    // ------------------------------------------------------------ uprawnienia

    private fun requiredPermissions(): Array<String> =
        if (Build.VERSION.SDK_INT >= 31) {
            arrayOf(Manifest.permission.BLUETOOTH_SCAN, Manifest.permission.BLUETOOTH_CONNECT)
        } else {
            arrayOf(Manifest.permission.ACCESS_FINE_LOCATION)
        }

    private var afterPermissions: (() -> Unit)? = null

    private fun withPermissions(action: () -> Unit) {
        val missing = requiredPermissions().filter { checkSelfPermission(it) != PackageManager.PERMISSION_GRANTED }
        if (missing.isEmpty()) {
            action()
        } else {
            afterPermissions = action
            requestPermissions(missing.toTypedArray(), REQ_PERMS)
        }
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode != REQ_PERMS) return
        if (grantResults.isNotEmpty() && grantResults.all { it == PackageManager.PERMISSION_GRANTED }) {
            afterPermissions?.invoke()
        } else {
            onStatus("Bez uprawnień Bluetooth aplikacja nie połączy się z gimbalem.")
        }
        afterPermissions = null
    }

    // ------------------------------------------------------------ urządzenia

    private fun clearDevices() {
        devicesView.removeAllViews()
        seenDevices.clear()
    }

    private fun connectLast() {
        val addr = prefs.getString("lastAddress", null)
        if (addr == null) {
            Toast.makeText(this, "Najpierw połącz się przez „Szukaj”.", Toast.LENGTH_SHORT).show()
            return
        }
        withPermissions { gimbal.connect(addr, prefs.getString("lastName", "") ?: "") }
    }

    override fun onScanResult(device: BluetoothDevice, name: String, rssi: Int, likely: Boolean) {
        if (!likely && !showAll.isChecked) return
        val label = "${name.ifBlank { "(bez nazwy)" }}  ${device.address}  $rssi dBm"
        val existing = seenDevices[device.address]
        if (existing != null) {
            existing.text = label
            return
        }
        val b = Button(this).apply {
            text = label
            setAllCaps(false)
            isFocusable = false
            setOnClickListener {
                prefs.edit().putString("lastAddress", device.address).putString("lastName", name).apply()
                gimbal.connect(device, name)
            }
        }
        seenDevices[device.address] = b
        devicesView.addView(b)
    }

    override fun onScanFinished() {
        if (!connected && seenDevices.isEmpty()) {
            onStatus("Nie znaleziono gimbala. Włącz go i upewnij się, że nie jest połączony z DJI Mimo. Możesz też zaznaczyć „Pokaż wszystkie”.")
        }
    }

    override fun onReady(name: String) {
        connected = true
        clearDevices()
        Toast.makeText(this, "Połączono z $name", Toast.LENGTH_SHORT).show()
    }

    override fun onDisconnected() {
        connected = false
        batteryView.text = ""
    }

    override fun onStatus(text: String) {
        statusView.text = text
    }

    override fun onBattery(percent: Int, charging: Boolean) {
        batteryView.text = "Bateria gimbala: $percent%" + if (charging) " (ładowanie)" else ""
    }

    override fun onPacket(packet: Duml.Packet) {
        // Telemetria (pozycja, bateria) przychodzi ciągle – nie zaśmiecamy logu.
        val telemetry = (packet.cmdSet == Duml.CMDSET_GIMBAL && (packet.cmd == 0x05 || packet.cmd == 0x27)) ||
            (packet.cmdSet == Duml.CMDSET_BATTERY && packet.cmd == Duml.CMD_BATTERY)
        if (!telemetry) onLog("← $packet")
    }

    override fun onLog(text: String) {
        logLines.addLast(text)
        while (logLines.size > 40) logLines.removeFirst()
        logView.text = logLines.joinToString("\n")
    }

    // ------------------------------------------------------------ pad

    private fun gamepads(): List<InputDevice> =
        InputDevice.getDeviceIds().toList().mapNotNull { InputDevice.getDevice(it) }.filter {
            !it.isVirtual && (it.sources and InputDevice.SOURCE_GAMEPAD == InputDevice.SOURCE_GAMEPAD ||
                it.sources and InputDevice.SOURCE_JOYSTICK == InputDevice.SOURCE_JOYSTICK)
        }

    private fun updatePadStatus() {
        val pads = gamepads()
        padView.text = if (pads.isEmpty()) {
            "Pad: nie wykryto. Połącz pad Xbox z TYM telefonem przez Bluetooth (przytrzymaj przycisk parowania na padzie)."
        } else {
            "Pad: " + pads.joinToString { it.name }
        }
    }

    override fun onInputDeviceAdded(deviceId: Int) = updatePadStatus()
    override fun onInputDeviceRemoved(deviceId: Int) {
        resetInput()
        updatePadStatus()
    }
    override fun onInputDeviceChanged(deviceId: Int) = updatePadStatus()

    private fun resetInput() {
        lx = 0f; ly = 0f; rx = 0f; ry = 0f; lt = 0f; rt = 0f
        hatX = 0f; hatY = 0f; dpadX = 0f; dpadY = 0f
    }

    private fun axis(event: MotionEvent, axis: Int): Float {
        val range = event.device?.getMotionRange(axis, event.source) ?: return 0f
        val v = event.getAxisValue(axis)
        return if (abs(v) > range.flat) v else 0f
    }

    override fun dispatchGenericMotionEvent(event: MotionEvent): Boolean {
        val isJoystick = event.source and InputDevice.SOURCE_JOYSTICK == InputDevice.SOURCE_JOYSTICK
        if (isJoystick && event.action == MotionEvent.ACTION_MOVE) {
            lx = axis(event, MotionEvent.AXIS_X)
            ly = axis(event, MotionEvent.AXIS_Y)
            rx = axis(event, MotionEvent.AXIS_Z)
            ry = axis(event, MotionEvent.AXIS_RZ)
            lt = maxOf(event.getAxisValue(MotionEvent.AXIS_LTRIGGER), event.getAxisValue(MotionEvent.AXIS_BRAKE))
            rt = maxOf(event.getAxisValue(MotionEvent.AXIS_RTRIGGER), event.getAxisValue(MotionEvent.AXIS_GAS))
            hatX = event.getAxisValue(MotionEvent.AXIS_HAT_X)
            hatY = event.getAxisValue(MotionEvent.AXIS_HAT_Y)
            return true
        }
        return super.dispatchGenericMotionEvent(event)
    }

    private fun isFromPad(event: KeyEvent): Boolean {
        val s = event.source
        return s and InputDevice.SOURCE_GAMEPAD == InputDevice.SOURCE_GAMEPAD ||
            s and InputDevice.SOURCE_JOYSTICK == InputDevice.SOURCE_JOYSTICK ||
            KeyEvent.isGamepadButton(event.keyCode)
    }

    // Przechwytujemy przyciski pada, zanim trafią do widoków (inaczej A
    // "klikałby" przyciski na ekranie, a B zamykałby aplikację).
    override fun dispatchKeyEvent(event: KeyEvent): Boolean {
        if (!isFromPad(event)) return super.dispatchKeyEvent(event)
        val down = event.action == KeyEvent.ACTION_DOWN
        val first = down && event.repeatCount == 0
        if (first) onLog("pad: ${KeyEvent.keyCodeToString(event.keyCode).removePrefix("KEYCODE_")}")
        when (event.keyCode) {
            KeyEvent.KEYCODE_DPAD_LEFT -> dpadX = if (down) -1f else 0f
            KeyEvent.KEYCODE_DPAD_RIGHT -> dpadX = if (down) 1f else 0f
            KeyEvent.KEYCODE_DPAD_UP -> dpadY = if (down) -1f else 0f
            KeyEvent.KEYCODE_DPAD_DOWN -> dpadY = if (down) 1f else 0f
            KeyEvent.KEYCODE_DPAD_CENTER, KeyEvent.KEYCODE_BUTTON_A -> if (first) gimbal.recenter()
            KeyEvent.KEYCODE_BUTTON_B, KeyEvent.KEYCODE_BACK -> if (first) {
                resetInput()
                gimbal.stop()
            }
            KeyEvent.KEYCODE_BUTTON_X -> if (first) gimbal.setMode(0)
            KeyEvent.KEYCODE_BUTTON_Y -> if (first) gimbal.setMode(1)
            KeyEvent.KEYCODE_BUTTON_L1 -> if (first) {
                speedBar.progress = (speedBar.progress - 1).coerceAtLeast(0); savePrefs()
            }
            KeyEvent.KEYCODE_BUTTON_R1 -> if (first) {
                speedBar.progress = (speedBar.progress + 1).coerceAtMost(speedBar.max); savePrefs()
            }
            KeyEvent.KEYCODE_BUTTON_SELECT -> if (first) invertTilt.isChecked = !invertTilt.isChecked
            KeyEvent.KEYCODE_BUTTON_START -> if (first && !connected) connectLast()
            // Wciśnięcie gałek celowo nic nie robi – łatwo je kliknąć przypadkiem.
            KeyEvent.KEYCODE_BUTTON_THUMBL, KeyEvent.KEYCODE_BUTTON_THUMBR -> {}
            else -> return super.dispatchKeyEvent(event)
        }
        return true
    }

    // ------------------------------------------------------------ pętla sterowania

    private val tick = object : Runnable {
        override fun run() {
            sendMotion()
            main.postDelayed(this, TICK_MS)
        }
    }

    private fun sendMotion() {
        val mult = StickMath.triggerMultiplier(lt, rt)
        val pan = invertPan.isChecked
        val tilt = invertTilt.isChecked
        val coarse = StickMath.toSpeeds(lx, ly, maxSpeed, mult, pan, tilt)
        val fine = StickMath.toSpeeds(rx, ry, maxSpeed, mult * PRECISION, pan, tilt)
        val dx = if (dpadX != 0f) dpadX else hatX
        val dy = if (dpadY != 0f) dpadY else hatY
        val nudge = StickMath.toSpeeds(dx, dy, maxSpeed, mult * DPAD_SPEED, pan, tilt, expo = 0f)
        val yaw = (coarse.first + fine.first + nudge.first).coerceIn(-3600, 3600)
        val pitch = (coarse.second + fine.second + nudge.second).coerceIn(-3600, 3600)
        val moving = yaw != 0 || pitch != 0

        liveView.text = "obrót: %+5.1f°/s   pochylenie: %+5.1f°/s".format(yaw / 10f, pitch / 10f)

        if (!connected) {
            wasMoving = false
            return
        }
        if (moving) {
            if (pwmMode.isChecked) {
                // W trybie PWM wysyłamy wychylenie (-1..1) zamiast prędkości.
                gimbal.sendPwm(yaw / 1800f, pitch / 1800f)
            } else {
                gimbal.sendSpeed(yaw, pitch)
            }
            if (logMotion.isChecked) onLog("→ ruch yaw=$yaw pitch=$pitch")
        } else if (wasMoving) {
            gimbal.stop()
        }
        wasMoving = moving
    }

    private fun slotTest(slot: Int) {
        if (!connected) {
            Toast.makeText(this, "Najpierw połącz gimbal.", Toast.LENGTH_SHORT).show()
            return
        }
        onLog("→ test osi ${slot + 1}: ruch przez 1 s")
        val end = System.currentTimeMillis() + 1000
        val r = object : Runnable {
            override fun run() {
                if (System.currentTimeMillis() < end) {
                    gimbal.sendSlotTest(slot, 300)
                    main.postDelayed(this, TICK_MS)
                } else {
                    gimbal.stop()
                }
            }
        }
        main.post(r)
    }
}
