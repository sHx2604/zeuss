'use client'

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Activity, AlertTriangle, CheckCircle, Wifi, WifiOff } from 'lucide-react'

interface StatusBarProps {
  totalDevices: number
  activeDevices: number
  errorDevices: number
  mqttStatus: 'connected' | 'disconnected' | 'error'
}

export default function StatusBar({
  totalDevices,
  activeDevices,
  errorDevices,
  mqttStatus
}: StatusBarProps) {
  const inactiveDevices = totalDevices - activeDevices - errorDevices

  const getMqttStatusColor = () => {
    switch (mqttStatus) {
      case 'connected': return 'text-green-500'
      case 'disconnected': return 'text-yellow-500'
      case 'error': return 'text-red-500'
      default: return 'text-gray-500'
    }
  }

  const getMqttIcon = () => {
    switch (mqttStatus) {
      case 'connected': return <Wifi className="h-4 w-4" />
      case 'disconnected':
      case 'error':
        return <WifiOff className="h-4 w-4" />
      default: return <WifiOff className="h-4 w-4" />
    }
  }

  return (
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
      {/* Total Devices */}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
          <CardTitle className="text-sm font-medium">Total Perangkat</CardTitle>
          <Activity className="h-4 w-4 text-muted-foreground" />
        </CardHeader>
        <CardContent>
          <div className="text-2xl font-bold">{totalDevices}</div>
          <p className="text-xs text-muted-foreground">
            Semua relay terdaftar
          </p>
        </CardContent>
      </Card>

      {/* Active Devices */}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
          <CardTitle className="text-sm font-medium">Perangkat Aktif</CardTitle>
          <CheckCircle className="h-4 w-4 text-green-500" />
        </CardHeader>
        <CardContent>
          <div className="text-2xl font-bold text-green-600">{activeDevices}</div>
          <p className="text-xs text-muted-foreground">
            Sedang beroperasi
          </p>
        </CardContent>
      </Card>

      {/* Inactive Devices */}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
          <CardTitle className="text-sm font-medium">Perangkat Mati</CardTitle>
          <div className="h-4 w-4 rounded-full bg-gray-400" />
        </CardHeader>
        <CardContent>
          <div className="text-2xl font-bold text-gray-600">{inactiveDevices}</div>
          <p className="text-xs text-muted-foreground">
            Dalam kondisi off
          </p>
        </CardContent>
      </Card>

      {/* Error Devices */}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
          <CardTitle className="text-sm font-medium">Perangkat Error</CardTitle>
          <AlertTriangle className="h-4 w-4 text-red-500" />
        </CardHeader>
        <CardContent>
          <div className="text-2xl font-bold text-red-600">{errorDevices}</div>
          <p className="text-xs text-muted-foreground">
            Memerlukan perhatian
          </p>
        </CardContent>
      </Card>

      {/* MQTT Status */}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
          <CardTitle className="text-sm font-medium">Status MQTT</CardTitle>
          <div className={getMqttStatusColor()}>
            {getMqttIcon()}
          </div>
        </CardHeader>
        <CardContent>
          <div className="flex items-center space-x-2">
            <Badge
              variant={mqttStatus === 'connected' ? 'default' : 'destructive'}
              className={
                mqttStatus === 'connected'
                  ? 'bg-green-100 text-green-800 hover:bg-green-200 dark:bg-green-900 dark:text-green-200'
                  : ''
              }
            >
              {mqttStatus === 'connected' ? 'Terhubung' :
               mqttStatus === 'disconnected' ? 'Terputus' : 'Error'}
            </Badge>
          </div>
          <p className="text-xs text-muted-foreground mt-1">
            {mqttStatus === 'connected' ? 'Siap menerima data' : 'Koneksi bermasalah'}
          </p>
        </CardContent>
      </Card>
    </div>
  )
}
