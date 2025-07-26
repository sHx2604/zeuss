'use client'

import { useState } from 'react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import {
  Wifi,
  WifiOff,
  RefreshCw,
  Settings,
  BarChart3,
  Power,
  AlertTriangle,
  TrendingUp,
  Database,
  Smartphone
} from 'lucide-react'

interface RelayDevice {
  id: string
  name: string
  location: string
  status: 'on' | 'off' | 'error'
  lastUpdate: Date
  voltage: number
  current: number
  power: number
  temperature: number
}

interface ControlPanelProps {
  devices: RelayDevice[]
  mqttStatus: 'connected' | 'disconnected' | 'error'
  onMqttReconnect: () => void
}

export default function ControlPanel({ devices, mqttStatus, onMqttReconnect }: ControlPanelProps) {
  const [isReconnecting, setIsReconnecting] = useState(false)

  const totalPowerConsumption = devices
    .filter(d => d.status === 'on')
    .reduce((sum, device) => sum + device.power, 0)

  const averageTemperature = devices.length > 0
    ? devices.reduce((sum, device) => sum + device.temperature, 0) / devices.length
    : 0

  const handleReconnect = async () => {
    setIsReconnecting(true)
    await new Promise(resolve => setTimeout(resolve, 2000)) // Simulasi reconnect
    onMqttReconnect()
    setIsReconnecting(false)
  }

  return (
    <div className="space-y-4">
      {/* System Status */}
      <Card>
        <CardHeader>
          <CardTitle className="text-lg flex items-center">
            <BarChart3 className="h-5 w-5 mr-2" />
            Status Sistem
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {/* MQTT Connection */}
          <div className="flex items-center justify-between">
            <div className="flex items-center space-x-2">
              {mqttStatus === 'connected' ? (
                <Wifi className="h-4 w-4 text-green-500" />
              ) : (
                <WifiOff className="h-4 w-4 text-red-500" />
              )}
              <span className="text-sm font-medium">MQTT Broker</span>
            </div>
            <Badge
              variant={mqttStatus === 'connected' ? 'default' : 'destructive'}
              className={
                mqttStatus === 'connected'
                  ? 'bg-green-100 text-green-800 hover:bg-green-200 dark:bg-green-900 dark:text-green-200'
                  : ''
              }
            >
              {mqttStatus === 'connected' ? 'Terhubung' : 'Terputus'}
            </Badge>
          </div>

          {mqttStatus !== 'connected' && (
            <Button
              onClick={handleReconnect}
              disabled={isReconnecting}
              variant="outline"
              size="sm"
              className="w-full"
            >
              <RefreshCw className={`h-4 w-4 mr-2 ${isReconnecting ? 'animate-spin' : ''}`} />
              {isReconnecting ? 'Menghubungkan...' : 'Hubungkan Ulang'}
            </Button>
          )}

          <Separator />

          {/* System Statistics */}
          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <div className="flex items-center space-x-2">
                <Power className="h-4 w-4 text-blue-500" />
                <span className="text-sm">Total Konsumsi</span>
              </div>
              <span className="font-semibold">{totalPowerConsumption.toFixed(0)} W</span>
            </div>

            <div className="flex items-center justify-between">
              <div className="flex items-center space-x-2">
                <TrendingUp className="h-4 w-4 text-orange-500" />
                <span className="text-sm">Suhu Rata-rata</span>
              </div>
              <span className="font-semibold">{averageTemperature.toFixed(1)}°C</span>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Quick Actions */}
      <Card>
        <CardHeader>
          <CardTitle className="text-lg flex items-center">
            <Settings className="h-5 w-5 mr-2" />
            Aksi Cepat
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <Button variant="outline" className="w-full justify-start">
            <Power className="h-4 w-4 mr-2" />
            Matikan Semua Relay
          </Button>

          <Button variant="outline" className="w-full justify-start">
            <RefreshCw className="h-4 w-4 mr-2" />
            Reset Semua Error
          </Button>

          <Button variant="outline" className="w-full justify-start">
            <Database className="h-4 w-4 mr-2" />
            Export Data Log
          </Button>

          <Button variant="outline" className="w-full justify-start">
            <Smartphone className="h-4 w-4 mr-2" />
            Mode Otomatis
          </Button>
        </CardContent>
      </Card>

      {/* Alert Panel */}
      {devices.some(d => d.status === 'error') && (
        <Card className="border-red-200 bg-red-50 dark:bg-red-950/50 dark:border-red-800">
          <CardHeader>
            <CardTitle className="text-lg flex items-center text-red-700 dark:text-red-400">
              <AlertTriangle className="h-5 w-5 mr-2" />
              Peringatan Sistem
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-2">
              {devices
                .filter(d => d.status === 'error')
                .map(device => (
                  <div key={device.id} className="text-sm">
                    <span className="font-medium">{device.name}</span>
                    <p className="text-red-600 dark:text-red-400">
                      Perangkat mengalami error dan memerlukan reset
                    </p>
                  </div>
                ))}
            </div>
          </CardContent>
        </Card>
      )}

      {/* Integration Info */}
      <Card className="bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-950/20 dark:to-indigo-950/20 border-blue-200 dark:border-blue-800">
        <CardHeader>
          <CardTitle className="text-lg text-blue-700 dark:text-blue-400">
            Integrasi PHP
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="space-y-2 text-sm text-blue-600 dark:text-blue-300">
            <p>✓ API endpoints siap</p>
            <p>✓ Database schema tersedia</p>
            <p>✓ User authentication hooks</p>
            <p>✓ Billing system integration</p>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
