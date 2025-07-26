'use client'

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import {
  Power,
  MapPin,
  Zap,
  Thermometer,
  Activity,
  AlertTriangle,
  RotateCcw,
  Clock
} from 'lucide-react'
import { formatDistanceToNow } from 'date-fns'
import { id } from 'date-fns/locale'

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

interface RelayCardProps {
  device: RelayDevice
  onToggle: () => void
  onReset: () => void
}

export default function RelayCard({ device, onToggle, onReset }: RelayCardProps) {
  const getStatusColor = () => {
    switch (device.status) {
      case 'on': return 'text-green-500'
      case 'off': return 'text-gray-500'
      case 'error': return 'text-red-500'
      default: return 'text-gray-500'
    }
  }

  const getStatusBadge = () => {
    switch (device.status) {
      case 'on':
        return <Badge className="bg-green-100 text-green-800 hover:bg-green-200 dark:bg-green-900 dark:text-green-200">AKTIF</Badge>
      case 'off':
        return <Badge variant="secondary">MATI</Badge>
      case 'error':
        return <Badge variant="destructive">ERROR</Badge>
      default:
        return <Badge variant="secondary">UNKNOWN</Badge>
    }
  }

  const formatLastUpdate = (date: Date) => {
    try {
      // Avoid hydration mismatch by using relative time
      const diffInMinutes = Math.floor((Date.now() - date.getTime()) / (1000 * 60))
      if (diffInMinutes < 1) return 'baru saja'
      if (diffInMinutes < 60) return `${diffInMinutes} menit yang lalu`
      const diffInHours = Math.floor(diffInMinutes / 60)
      if (diffInHours < 24) return `${diffInHours} jam yang lalu`
      const diffInDays = Math.floor(diffInHours / 24)
      return `${diffInDays} hari yang lalu`
    } catch {
      return 'baru saja'
    }
  }

  return (
    <Card className="group hover:shadow-lg transition-all duration-200 border-2 hover:border-primary/20">
      <CardHeader className="pb-3">
        <div className="flex items-start justify-between">
          <div>
            <CardTitle className="text-lg font-semibold">{device.name}</CardTitle>
            <div className="flex items-center text-sm text-muted-foreground mt-1">
              <MapPin className="h-3 w-3 mr-1" />
              {device.location}
            </div>
          </div>
          <div className="flex flex-col items-end space-y-2">
            {getStatusBadge()}
            <Power className={`h-5 w-5 ${getStatusColor()}`} />
          </div>
        </div>
      </CardHeader>

      <CardContent className="space-y-4">
        {/* Controls */}
        <div className="flex items-center justify-between p-3 rounded-lg bg-muted/50">
          <span className="font-medium">Kontrol Power</span>
          <div className="flex items-center space-x-2">
            {device.status === 'error' ? (
              <Button
                onClick={onReset}
                variant="outline"
                size="sm"
                className="text-red-600 border-red-200 hover:bg-red-50"
              >
                <RotateCcw className="h-4 w-4 mr-1" />
                Reset
              </Button>
            ) : (
              <Switch
                checked={device.status === 'on'}
                onCheckedChange={onToggle}
              />
            )}
          </div>
        </div>

        {/* Monitoring Data */}
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1">
            <div className="flex items-center text-xs text-muted-foreground">
              <Zap className="h-3 w-3 mr-1" />
              Tegangan
            </div>
            <div className="font-semibold">
              {device.voltage.toFixed(1)} V
            </div>
          </div>

          <div className="space-y-1">
            <div className="flex items-center text-xs text-muted-foreground">
              <Activity className="h-3 w-3 mr-1" />
              Arus
            </div>
            <div className="font-semibold">
              {device.current.toFixed(2)} A
            </div>
          </div>

          <div className="space-y-1">
            <div className="flex items-center text-xs text-muted-foreground">
              <Power className="h-3 w-3 mr-1" />
              Daya
            </div>
            <div className="font-semibold">
              {device.power.toFixed(0)} W
            </div>
          </div>

          <div className="space-y-1">
            <div className="flex items-center text-xs text-muted-foreground">
              <Thermometer className="h-3 w-3 mr-1" />
              Suhu
            </div>
            <div className="font-semibold">
              {device.temperature.toFixed(1)}°C
            </div>
          </div>
        </div>

        {/* Status Indicators */}
        <div className="pt-2 border-t">
          <div className="flex items-center justify-between text-xs text-muted-foreground">
            <div className="flex items-center">
              <Clock className="h-3 w-3 mr-1" />
              Update: {formatLastUpdate(device.lastUpdate)}
            </div>
            {device.status === 'error' && (
              <div className="flex items-center text-red-500">
                <AlertTriangle className="h-3 w-3 mr-1" />
                Perlu Perhatian
              </div>
            )}
          </div>
        </div>
      </CardContent>
    </Card>
  )
}
