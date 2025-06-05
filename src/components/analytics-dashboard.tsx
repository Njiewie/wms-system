"use client"

import { useState } from "react"
import {
  BarChart3,
  TrendingUp,
  TrendingDown,
  Package,
  Truck,
  Clock,
  DollarSign,
  Users,
  MapPin,
  AlertTriangle,
  CheckCircle,
  Target,
  Calendar,
  Download,
  RefreshCw,
  Filter,
  Eye,
  ArrowUpRight,
  ArrowDownRight,
  Minus
} from "lucide-react"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Progress } from "@/components/ui/progress"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"

// Mock analytics data
const analyticsData = {
  overview: {
    totalRevenue: 2456789,
    revenueChange: 12.5,
    totalOrders: 1567,
    ordersChange: 8.3,
    inventoryValue: 890234,
    inventoryChange: -2.1,
    customerSatisfaction: 94.7,
    satisfactionChange: 1.2
  },
  performance: {
    pickingAccuracy: 99.2,
    pickingSpeed: 187, // items per hour
    orderFulfillmentTime: 2.4, // hours
    inventoryTurnover: 8.7,
    warehouseUtilization: 87.3,
    onTimeDelivery: 96.8
  },
  trends: {
    orderVolume: [120, 135, 148, 162, 156, 171, 189, 204, 198, 215, 223, 241],
    revenue: [45000, 52000, 48000, 61000, 58000, 67000, 72000, 69000, 74000, 78000, 83000, 89000],
    pickingProductivity: [85, 87, 89, 91, 88, 92, 94, 96, 95, 97, 98, 99]
  },
  topPerformers: [
    { name: "John Smith", role: "Picker", productivity: 127, accuracy: 99.8 },
    { name: "Maria Garcia", role: "Supervisor", productivity: 115, accuracy: 99.5 },
    { name: "David Chen", role: "Picker", productivity: 112, accuracy: 99.2 },
    { name: "Sarah Johnson", role: "Packer", productivity: 108, accuracy: 98.9 },
    { name: "Mike Wilson", role: "Receiver", productivity: 105, accuracy: 98.7 }
  ],
  inventoryHealth: {
    fastMoving: 342,
    slowMoving: 89,
    obsolete: 23,
    lowStock: 45,
    overstock: 12,
    expiringItems: 8
  },
  clientPerformance: [
    { name: "TechCorp", orders: 234, revenue: 456789, satisfaction: 98.2, growth: 15.3 },
    { name: "RetailMax", orders: 187, revenue: 342156, satisfaction: 95.8, growth: 8.7 },
    { name: "GlobalSupply", orders: 156, revenue: 289034, satisfaction: 97.1, growth: 12.1 },
    { name: "QuickShip", orders: 98, revenue: 187234, satisfaction: 94.3, growth: -2.4 },
    { name: "MegaStore", orders: 145, revenue: 234567, satisfaction: 96.5, growth: 6.8 }
  ]
}

function MetricCard({
  title,
  value,
  change,
  icon: Icon,
  format = "number",
  suffix = "",
  description
}: {
  title: string
  value: number
  change: number
  icon: any
  format?: "number" | "currency" | "percentage"
  suffix?: string
  description?: string
}) {
  const formatValue = (val: number) => {
    switch (format) {
      case "currency":
        return `$${val.toLocaleString()}`
      case "percentage":
        return `${val}%`
      default:
        return val.toLocaleString()
    }
  }

  const changeIcon = change > 0 ? ArrowUpRight : change < 0 ? ArrowDownRight : Minus
  const changeColor = change > 0 ? "text-green-600" : change < 0 ? "text-red-600" : "text-gray-500"

  return (
    <Card className="wms-stat-card animate-fade-in">
      <CardContent className="p-6">
        <div className="flex items-center justify-between">
          <div>
            <p className="text-sm text-muted-foreground mb-1">{title}</p>
            <p className="text-2xl font-bold">
              {formatValue(value)}{suffix}
            </p>
            {description && (
              <p className="text-xs text-muted-foreground mt-1">{description}</p>
            )}
          </div>
          <div className="flex flex-col items-end gap-2">
            <Icon className="h-8 w-8 text-muted-foreground" />
            <div className={`flex items-center gap-1 text-xs ${changeColor}`}>
              {React.createElement(changeIcon, { className: "h-3 w-3" })}
              <span>{Math.abs(change)}%</span>
            </div>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

function SimpleChart({ data, label, color = "#3b82f6" }: { data: number[], label: string, color?: string }) {
  const max = Math.max(...data)
  const min = Math.min(...data)
  const range = max - min

  return (
    <div className="space-y-2">
      <div className="flex justify-between text-sm">
        <span className="text-muted-foreground">{label}</span>
        <span className="font-medium">{data[data.length - 1].toLocaleString()}</span>
      </div>
      <div className="h-20 flex items-end gap-1">
        {data.map((value, index) => {
          const height = range > 0 ? ((value - min) / range) * 100 : 50
          return (
            <div
              key={index}
              className="flex-1 rounded-t-sm transition-all duration-300 hover:opacity-80"
              style={{
                height: `${Math.max(height, 5)}%`,
                backgroundColor: color
              }}
              title={`${label}: ${value.toLocaleString()}`}
            />
          )
        })}
      </div>
    </div>
  )
}

function PerformanceGauge({ value, max = 100, label, color = "#3b82f6" }: {
  value: number
  max?: number
  label: string
  color?: string
}) {
  const percentage = (value / max) * 100

  return (
    <div className="space-y-3">
      <div className="flex justify-between items-center">
        <span className="text-sm text-muted-foreground">{label}</span>
        <span className="text-lg font-bold">{value}%</span>
      </div>
      <Progress value={percentage} className="h-2" />
      <div className="text-xs text-muted-foreground">
        Target: {max}%
      </div>
    </div>
  )
}

export function AnalyticsDashboard() {
  const [timeRange, setTimeRange] = useState("30d")
  const [selectedView, setSelectedView] = useState("overview")

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="wms-page-header">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold text-foreground">Analytics & Performance</h1>
            <p className="text-muted-foreground">Comprehensive insights into warehouse operations and performance metrics</p>
          </div>
          <div className="flex gap-2">
            <Select value={timeRange} onValueChange={setTimeRange}>
              <SelectTrigger className="w-32">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="7d">Last 7 days</SelectItem>
                <SelectItem value="30d">Last 30 days</SelectItem>
                <SelectItem value="90d">Last 3 months</SelectItem>
                <SelectItem value="1y">Last year</SelectItem>
              </SelectContent>
            </Select>
            <Button variant="outline" className="gap-2">
              <Download className="h-4 w-4" />
              Export Report
            </Button>
            <Button variant="outline" className="gap-2">
              <RefreshCw className="h-4 w-4" />
              Refresh
            </Button>
          </div>
        </div>
      </div>

      {/* Key Metrics Overview */}
      <div className="grid gap-6 md:grid-cols-4">
        <MetricCard
          title="Total Revenue"
          value={analyticsData.overview.totalRevenue}
          change={analyticsData.overview.revenueChange}
          icon={DollarSign}
          format="currency"
          description="Monthly revenue"
        />
        <MetricCard
          title="Total Orders"
          value={analyticsData.overview.totalOrders}
          change={analyticsData.overview.ordersChange}
          icon={Package}
          description="Orders processed"
        />
        <MetricCard
          title="Inventory Value"
          value={analyticsData.overview.inventoryValue}
          change={analyticsData.overview.inventoryChange}
          icon={BarChart3}
          format="currency"
          description="Current stock value"
        />
        <MetricCard
          title="Customer Satisfaction"
          value={analyticsData.overview.customerSatisfaction}
          change={analyticsData.overview.satisfactionChange}
          icon={Target}
          format="percentage"
          description="Customer rating"
        />
      </div>

      {/* Main Analytics Tabs */}
      <Tabs value={selectedView} onValueChange={setSelectedView} className="space-y-6">
        <TabsList className="grid w-full grid-cols-4">
          <TabsTrigger value="overview">Overview</TabsTrigger>
          <TabsTrigger value="performance">Performance</TabsTrigger>
          <TabsTrigger value="inventory">Inventory</TabsTrigger>
          <TabsTrigger value="clients">Clients</TabsTrigger>
        </TabsList>

        {/* Overview Tab */}
        <TabsContent value="overview" className="space-y-6">
          <div className="grid gap-6 lg:grid-cols-3">
            {/* Order Volume Trend */}
            <Card className="animate-slide-up">
              <CardHeader>
                <CardTitle className="text-base">Order Volume Trend</CardTitle>
                <CardDescription>Monthly order volume over the last 12 months</CardDescription>
              </CardHeader>
              <CardContent>
                <SimpleChart
                  data={analyticsData.trends.orderVolume}
                  label="Orders"
                  color="#3b82f6"
                />
              </CardContent>
            </Card>

            {/* Revenue Trend */}
            <Card className="animate-slide-up" style={{ animationDelay: '0.1s' }}>
              <CardHeader>
                <CardTitle className="text-base">Revenue Trend</CardTitle>
                <CardDescription>Monthly revenue growth trajectory</CardDescription>
              </CardHeader>
              <CardContent>
                <SimpleChart
                  data={analyticsData.trends.revenue}
                  label="Revenue ($)"
                  color="#10b981"
                />
              </CardContent>
            </Card>

            {/* Productivity Trend */}
            <Card className="animate-slide-up" style={{ animationDelay: '0.2s' }}>
              <CardHeader>
                <CardTitle className="text-base">Picking Productivity</CardTitle>
                <CardDescription>Monthly picking accuracy percentage</CardDescription>
              </CardHeader>
              <CardContent>
                <SimpleChart
                  data={analyticsData.trends.pickingProductivity}
                  label="Accuracy (%)"
                  color="#f59e0b"
                />
              </CardContent>
            </Card>
          </div>

          {/* Top Performers */}
          <Card className="animate-slide-up" style={{ animationDelay: '0.3s' }}>
            <CardHeader>
              <CardTitle>Top Performers</CardTitle>
              <CardDescription>Highest performing team members this month</CardDescription>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                {analyticsData.topPerformers.map((performer, index) => (
                  <div key={performer.name} className="flex items-center justify-between p-3 rounded-lg bg-muted/30">
                    <div className="flex items-center gap-3">
                      <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-primary-foreground text-sm font-medium">
                        {index + 1}
                      </div>
                      <div>
                        <p className="font-medium">{performer.name}</p>
                        <p className="text-sm text-muted-foreground">{performer.role}</p>
                      </div>
                    </div>
                    <div className="text-right">
                      <p className="font-medium">{performer.productivity}% productivity</p>
                      <p className="text-sm text-muted-foreground">{performer.accuracy}% accuracy</p>
                    </div>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Performance Tab */}
        <TabsContent value="performance" className="space-y-6">
          <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            <Card className="animate-fade-in">
              <CardHeader>
                <CardTitle className="text-base">Picking Accuracy</CardTitle>
              </CardHeader>
              <CardContent>
                <PerformanceGauge
                  value={analyticsData.performance.pickingAccuracy}
                  label="Current Performance"
                  color="#10b981"
                />
              </CardContent>
            </Card>

            <Card className="animate-fade-in" style={{ animationDelay: '0.1s' }}>
              <CardHeader>
                <CardTitle className="text-base">Warehouse Utilization</CardTitle>
              </CardHeader>
              <CardContent>
                <PerformanceGauge
                  value={analyticsData.performance.warehouseUtilization}
                  label="Space Utilization"
                  color="#3b82f6"
                />
              </CardContent>
            </Card>

            <Card className="animate-fade-in" style={{ animationDelay: '0.2s' }}>
              <CardHeader>
                <CardTitle className="text-base">On-Time Delivery</CardTitle>
              </CardHeader>
              <CardContent>
                <PerformanceGauge
                  value={analyticsData.performance.onTimeDelivery}
                  label="Delivery Performance"
                  color="#f59e0b"
                />
              </CardContent>
            </Card>
          </div>

          {/* Additional Performance Metrics */}
          <div className="grid gap-6 md:grid-cols-3">
            <Card className="animate-slide-up">
              <CardContent className="p-6">
                <div className="flex items-center gap-4">
                  <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/20">
                    <Clock className="h-6 w-6 text-blue-600" />
                  </div>
                  <div>
                    <p className="text-sm text-muted-foreground">Avg Fulfillment Time</p>
                    <p className="text-2xl font-bold">{analyticsData.performance.orderFulfillmentTime}h</p>
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card className="animate-slide-up" style={{ animationDelay: '0.1s' }}>
              <CardContent className="p-6">
                <div className="flex items-center gap-4">
                  <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900/20">
                    <TrendingUp className="h-6 w-6 text-green-600" />
                  </div>
                  <div>
                    <p className="text-sm text-muted-foreground">Inventory Turnover</p>
                    <p className="text-2xl font-bold">{analyticsData.performance.inventoryTurnover}x</p>
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card className="animate-slide-up" style={{ animationDelay: '0.2s' }}>
              <CardContent className="p-6">
                <div className="flex items-center gap-4">
                  <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-purple-100 dark:bg-purple-900/20">
                    <Target className="h-6 w-6 text-purple-600" />
                  </div>
                  <div>
                    <p className="text-sm text-muted-foreground">Picking Speed</p>
                    <p className="text-2xl font-bold">{analyticsData.performance.pickingSpeed}/h</p>
                  </div>
                </div>
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        {/* Inventory Tab */}
        <TabsContent value="inventory" className="space-y-6">
          <div className="grid gap-6 md:grid-cols-3 lg:grid-cols-6">
            <Card className="animate-fade-in">
              <CardContent className="p-4">
                <div className="text-center">
                  <p className="text-sm text-muted-foreground">Fast Moving</p>
                  <p className="text-2xl font-bold text-green-600">{analyticsData.inventoryHealth.fastMoving}</p>
                </div>
              </CardContent>
            </Card>

            <Card className="animate-fade-in" style={{ animationDelay: '0.1s' }}>
              <CardContent className="p-4">
                <div className="text-center">
                  <p className="text-sm text-muted-foreground">Slow Moving</p>
                  <p className="text-2xl font-bold text-yellow-600">{analyticsData.inventoryHealth.slowMoving}</p>
                </div>
              </CardContent>
            </Card>

            <Card className="animate-fade-in" style={{ animationDelay: '0.2s' }}>
              <CardContent className="p-4">
                <div className="text-center">
                  <p className="text-sm text-muted-foreground">Obsolete</p>
                  <p className="text-2xl font-bold text-red-600">{analyticsData.inventoryHealth.obsolete}</p>
                </div>
              </CardContent>
            </Card>

            <Card className="animate-fade-in" style={{ animationDelay: '0.3s' }}>
              <CardContent className="p-4">
                <div className="text-center">
                  <p className="text-sm text-muted-foreground">Low Stock</p>
                  <p className="text-2xl font-bold text-orange-600">{analyticsData.inventoryHealth.lowStock}</p>
                </div>
              </CardContent>
            </Card>

            <Card className="animate-fade-in" style={{ animationDelay: '0.4s' }}>
              <CardContent className="p-4">
                <div className="text-center">
                  <p className="text-sm text-muted-foreground">Overstock</p>
                  <p className="text-2xl font-bold text-blue-600">{analyticsData.inventoryHealth.overstock}</p>
                </div>
              </CardContent>
            </Card>

            <Card className="animate-fade-in" style={{ animationDelay: '0.5s' }}>
              <CardContent className="p-4">
                <div className="text-center">
                  <p className="text-sm text-muted-foreground">Expiring Soon</p>
                  <p className="text-2xl font-bold text-purple-600">{analyticsData.inventoryHealth.expiringItems}</p>
                </div>
              </CardContent>
            </Card>
          </div>

          {/* Inventory Health Details */}
          <Card className="animate-slide-up">
            <CardHeader>
              <CardTitle>Inventory Health Analysis</CardTitle>
              <CardDescription>Detailed breakdown of inventory status and recommendations</CardDescription>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                <div className="grid gap-4 md:grid-cols-2">
                  <div className="space-y-3">
                    <h4 className="font-medium">Stock Movement Analysis</h4>
                    <div className="space-y-2">
                      <div className="flex justify-between items-center">
                        <span className="text-sm">Fast Moving Items</span>
                        <Badge className="bg-green-100 text-green-800">Healthy</Badge>
                      </div>
                      <Progress value={85} className="h-2" />
                    </div>
                    <div className="space-y-2">
                      <div className="flex justify-between items-center">
                        <span className="text-sm">Slow Moving Items</span>
                        <Badge className="bg-yellow-100 text-yellow-800">Monitor</Badge>
                      </div>
                      <Progress value={23} className="h-2" />
                    </div>
                  </div>

                  <div className="space-y-3">
                    <h4 className="font-medium">Stock Level Health</h4>
                    <div className="space-y-2">
                      <div className="flex justify-between items-center">
                        <span className="text-sm">Optimal Levels</span>
                        <Badge className="bg-green-100 text-green-800">Good</Badge>
                      </div>
                      <Progress value={78} className="h-2" />
                    </div>
                    <div className="space-y-2">
                      <div className="flex justify-between items-center">
                        <span className="text-sm">Needs Attention</span>
                        <Badge className="bg-red-100 text-red-800">Action Required</Badge>
                      </div>
                      <Progress value={22} className="h-2" />
                    </div>
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Clients Tab */}
        <TabsContent value="clients" className="space-y-6">
          <Card className="animate-slide-up">
            <CardHeader>
              <CardTitle>Client Performance Analysis</CardTitle>
              <CardDescription>Revenue, order volume, and satisfaction metrics by client</CardDescription>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                {analyticsData.clientPerformance.map((client, index) => (
                  <div key={client.name} className="flex items-center justify-between p-4 rounded-lg border bg-card">
                    <div className="flex items-center gap-4">
                      <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary text-primary-foreground font-medium">
                        {client.name.charAt(0)}
                      </div>
                      <div>
                        <p className="font-medium">{client.name}</p>
                        <p className="text-sm text-muted-foreground">{client.orders} orders</p>
                      </div>
                    </div>
                    <div className="flex items-center gap-6 text-sm">
                      <div className="text-right">
                        <p className="font-medium">${client.revenue.toLocaleString()}</p>
                        <p className="text-muted-foreground">Revenue</p>
                      </div>
                      <div className="text-right">
                        <p className="font-medium">{client.satisfaction}%</p>
                        <p className="text-muted-foreground">Satisfaction</p>
                      </div>
                      <div className="text-right">
                        <div className={`flex items-center gap-1 font-medium ${
                          client.growth > 0 ? 'text-green-600' : client.growth < 0 ? 'text-red-600' : 'text-gray-500'
                        }`}>
                          {client.growth > 0 ? <ArrowUpRight className="h-3 w-3" /> :
                           client.growth < 0 ? <ArrowDownRight className="h-3 w-3" /> :
                           <Minus className="h-3 w-3" />}
                          <span>{Math.abs(client.growth)}%</span>
                        </div>
                        <p className="text-muted-foreground">Growth</p>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  )
}
