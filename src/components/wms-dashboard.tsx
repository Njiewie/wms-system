"use client"

import { useState } from "react"
import {
  Package,
  TrendingUp,
  AlertTriangle,
  Truck,
  BarChart3,
  Plus,
  ArrowUpRight,
  ArrowDownRight,
  Clock,
  Users,
  MapPin,
  Search,
  Bell,
  Settings,
  Menu,
  X,
  ChevronRight,
  Eye,
  Edit,
  MoreHorizontal,
  PackageSearch,
  ClipboardList,
  FileText,
  Activity
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Sidebar, SidebarContent, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarProvider, SidebarTrigger } from "@/components/ui/sidebar"
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu"

// Mock data - in a real app, this would come from your API
const dashboardStats = {
  inventory: {
    totalItems: 15847,
    lowStock: 23,
    allocated: 2847,
    available: 12977,
    change: "+5.2%",
    trend: "up"
  },
  orders: {
    active: 156,
    shipped: 89,
    pending: 34,
    critical: 7,
    change: "+12.3%",
    trend: "up"
  },
  performance: {
    pickRate: 94.2,
    accuracy: 99.1,
    onTime: 96.8,
    utilization: 87.3
  }
}

const recentOrders = [
  { id: "ORD-2024-001", sku: "PRD-ABC-123", qty: 50, client: "TechCorp", status: "PICKED", priority: "high", created: "2h ago" },
  { id: "ORD-2024-002", sku: "PRD-XYZ-789", qty: 25, client: "RetailMax", status: "ALLOCATED", priority: "medium", created: "4h ago" },
  { id: "ORD-2024-003", sku: "PRD-DEF-456", qty: 100, client: "GlobalSupply", status: "RELEASED", priority: "low", created: "6h ago" },
  { id: "ORD-2024-004", sku: "PRD-GHI-012", qty: 75, client: "MegaStore", status: "HOLD", priority: "high", created: "8h ago" },
  { id: "ORD-2024-005", sku: "PRD-JKL-345", qty: 30, client: "QuickShip", status: "SHIPPED", priority: "medium", created: "10h ago" },
]

const lowStockItems = [
  { sku: "PRD-LOW-001", description: "Premium Widget A", current: 8, minimum: 50, location: "A1-B2-C3" },
  { sku: "PRD-LOW-002", description: "Essential Component B", current: 3, minimum: 25, location: "B2-C3-D4" },
  { sku: "PRD-LOW-003", description: "Critical Part C", current: 12, minimum: 100, location: "C3-D4-E5" },
  { sku: "PRD-LOW-004", description: "Standard Module D", current: 5, minimum: 30, location: "D4-E5-F6" },
]

const activities = [
  { type: "pick", description: "Order ORD-2024-001 picked", user: "John Smith", time: "5 min ago" },
  { type: "receive", description: "ASN ASN-2024-015 received", user: "Maria Garcia", time: "12 min ago" },
  { type: "ship", description: "Order ORD-2024-005 shipped", user: "David Chen", time: "18 min ago" },
  { type: "alert", description: "Low stock alert for PRD-LOW-002", user: "System", time: "22 min ago" },
]

const navItems = [
  { icon: BarChart3, label: "Dashboard", href: "/", active: true },
  { icon: Package, label: "Inventory", href: "/inventory" },
  { icon: ClipboardList, label: "Inbound", href: "/inbound" },
  { icon: Truck, label: "Outbound", href: "/outbound" },
  { icon: PackageSearch, label: "SKU Master", href: "/sku-master" },
  { icon: Users, label: "Clients", href: "/clients" },
  { icon: FileText, label: "Reports", href: "/reports" },
  { icon: Settings, label: "Settings", href: "/settings" },
]

function StatusBadge({ status }: { status: string }) {
  const statusConfig = {
    HOLD: "wms-badge-hold",
    RELEASED: "wms-badge-released",
    ALLOCATED: "wms-badge-allocated",
    PICKED: "wms-badge-picked",
    SHIPPED: "wms-badge-shipped"
  }

  return (
    <Badge className={`wms-badge-status ${statusConfig[status as keyof typeof statusConfig] || "wms-badge-hold"}`}>
      {status}
    </Badge>
  )
}

function PriorityBadge({ priority }: { priority: string }) {
  const colors = {
    high: "bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400",
    medium: "bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400",
    low: "bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400"
  }

  return (
    <Badge className={colors[priority as keyof typeof colors] || colors.medium}>
      {priority.toUpperCase()}
    </Badge>
  )
}

export function WMSDashboard() {
  const [sidebarOpen, setSidebarOpen] = useState(true)

  return (
    <SidebarProvider>
      <div className="flex h-screen bg-background">
        {/* Sidebar */}
        <Sidebar className="border-r">
          <SidebarHeader className="border-b p-4">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                <Package className="h-6 w-6" />
              </div>
              <div>
                <h2 className="text-lg font-bold">ECWMS</h2>
                <p className="text-xs text-muted-foreground">Enterprise WMS</p>
              </div>
            </div>
          </SidebarHeader>
          <SidebarContent className="p-4">
            <SidebarMenu>
              {navItems.map((item) => (
                <SidebarMenuItem key={item.href}>
                  <SidebarMenuButton
                    asChild
                    className={`wms-nav-item ${item.active ? 'active' : ''}`}
                  >
                    <a href={item.href}>
                      <item.icon className="h-4 w-4" />
                      <span>{item.label}</span>
                    </a>
                  </SidebarMenuButton>
                </SidebarMenuItem>
              ))}
            </SidebarMenu>
          </SidebarContent>
        </Sidebar>

        {/* Main Content */}
        <div className="flex-1 flex flex-col overflow-hidden">
          {/* Header */}
          <header className="border-b bg-background px-6 py-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-4">
                <SidebarTrigger className="lg:hidden" />
                <div>
                  <h1 className="text-2xl font-bold text-foreground">Dashboard</h1>
                  <p className="text-muted-foreground">Welcome back! Here's what's happening in your warehouse.</p>
                </div>
              </div>

              <div className="flex items-center gap-4">
                <div className="relative hidden sm:block">
                  <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    placeholder="Search orders, SKUs, locations..."
                    className="w-80 pl-10"
                  />
                </div>
                <Button variant="ghost" size="icon">
                  <Bell className="h-5 w-5" />
                </Button>
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon">
                      <Settings className="h-5 w-5" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end">
                    <DropdownMenuItem>Profile Settings</DropdownMenuItem>
                    <DropdownMenuItem>System Settings</DropdownMenuItem>
                    <DropdownMenuItem>Logout</DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              </div>
            </div>
          </header>

          {/* Main Content Area */}
          <main className="flex-1 overflow-auto p-6 space-y-6">
            {/* Quick Actions */}
            <div className="wms-action-bar">
              <div className="flex gap-2">
                <Button className="gap-2">
                  <Plus className="h-4 w-4" />
                  Create Order
                </Button>
                <Button variant="outline" className="gap-2">
                  <Package className="h-4 w-4" />
                  Receive Inventory
                </Button>
                <Button variant="outline" className="gap-2">
                  <Truck className="h-4 w-4" />
                  Ship Orders
                </Button>
              </div>
              <div className="text-sm text-muted-foreground">
                Last updated: Live data
              </div>
            </div>

            {/* Key Metrics */}
            <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
              <Card className="wms-stat-card animate-fade-in">
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">Total Inventory</CardTitle>
                  <Package className="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                  <div className="wms-metric">{dashboardStats.inventory.totalItems.toLocaleString()}</div>
                  <div className="flex items-center text-xs text-muted-foreground">
                    <ArrowUpRight className="mr-1 h-3 w-3 text-emerald-500" />
                    <span className="text-emerald-500">{dashboardStats.inventory.change}</span>
                    <span className="ml-1">from last month</span>
                  </div>
                </CardContent>
              </Card>

              <Card className="wms-stat-card animate-fade-in" style={{ animationDelay: '0.1s' }}>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">Active Orders</CardTitle>
                  <ClipboardList className="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                  <div className="wms-metric">{dashboardStats.orders.active}</div>
                  <div className="flex items-center text-xs text-muted-foreground">
                    <ArrowUpRight className="mr-1 h-3 w-3 text-emerald-500" />
                    <span className="text-emerald-500">{dashboardStats.orders.change}</span>
                    <span className="ml-1">from yesterday</span>
                  </div>
                </CardContent>
              </Card>

              <Card className="wms-stat-card animate-fade-in" style={{ animationDelay: '0.2s' }}>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">Pick Accuracy</CardTitle>
                  <TrendingUp className="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                  <div className="wms-metric">{dashboardStats.performance.accuracy}%</div>
                  <div className="flex items-center text-xs text-muted-foreground">
                    <ArrowUpRight className="mr-1 h-3 w-3 text-emerald-500" />
                    <span className="text-emerald-500">+0.3%</span>
                    <span className="ml-1">from last week</span>
                  </div>
                </CardContent>
              </Card>

              <Card className="wms-stat-card animate-fade-in" style={{ animationDelay: '0.3s' }}>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">Low Stock Alerts</CardTitle>
                  <AlertTriangle className="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                  <div className="wms-metric text-orange-600">{dashboardStats.inventory.lowStock}</div>
                  <div className="flex items-center text-xs text-muted-foreground">
                    <ArrowDownRight className="mr-1 h-3 w-3 text-red-500" />
                    <span className="text-red-500">-2</span>
                    <span className="ml-1">from yesterday</span>
                  </div>
                </CardContent>
              </Card>
            </div>

            {/* Main Content Grid */}
            <div className="grid gap-6 lg:grid-cols-3">
              {/* Recent Orders */}
              <div className="lg:col-span-2">
                <Card className="animate-slide-up">
                  <CardHeader>
                    <div className="flex items-center justify-between">
                      <div>
                        <CardTitle>Recent Orders</CardTitle>
                        <CardDescription>Latest order activities and status updates</CardDescription>
                      </div>
                      <Button variant="outline" size="sm" className="gap-2">
                        <Eye className="h-4 w-4" />
                        View All
                      </Button>
                    </div>
                  </CardHeader>
                  <CardContent>
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead>Order ID</TableHead>
                          <TableHead>SKU</TableHead>
                          <TableHead>Qty</TableHead>
                          <TableHead>Client</TableHead>
                          <TableHead>Status</TableHead>
                          <TableHead>Priority</TableHead>
                          <TableHead>Created</TableHead>
                          <TableHead></TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {recentOrders.map((order) => (
                          <TableRow key={order.id} className="wms-table-row">
                            <TableCell className="font-medium">{order.id}</TableCell>
                            <TableCell className="font-mono text-sm">{order.sku}</TableCell>
                            <TableCell>{order.qty}</TableCell>
                            <TableCell>{order.client}</TableCell>
                            <TableCell>
                              <StatusBadge status={order.status} />
                            </TableCell>
                            <TableCell>
                              <PriorityBadge priority={order.priority} />
                            </TableCell>
                            <TableCell className="text-muted-foreground">{order.created}</TableCell>
                            <TableCell>
                              <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                  <Button variant="ghost" size="icon">
                                    <MoreHorizontal className="h-4 w-4" />
                                  </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                  <DropdownMenuItem>
                                    <Eye className="mr-2 h-4 w-4" />
                                    View Details
                                  </DropdownMenuItem>
                                  <DropdownMenuItem>
                                    <Edit className="mr-2 h-4 w-4" />
                                    Edit Order
                                  </DropdownMenuItem>
                                </DropdownMenuContent>
                              </DropdownMenu>
                            </TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </CardContent>
                </Card>
              </div>

              {/* Sidebar Content */}
              <div className="space-y-6">
                {/* Low Stock Alerts */}
                <Card className="animate-slide-up" style={{ animationDelay: '0.1s' }}>
                  <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                      <AlertTriangle className="h-5 w-5 text-orange-500" />
                      Low Stock Alerts
                    </CardTitle>
                    <CardDescription>Items requiring immediate attention</CardDescription>
                  </CardHeader>
                  <CardContent className="space-y-4">
                    {lowStockItems.map((item) => (
                      <div key={item.sku} className="flex items-start justify-between p-3 rounded-lg border bg-orange-50 dark:bg-orange-950/10">
                        <div className="space-y-1">
                          <p className="font-medium text-sm">{item.sku}</p>
                          <p className="text-xs text-muted-foreground">{item.description}</p>
                          <div className="flex items-center gap-2 text-xs">
                            <MapPin className="h-3 w-3" />
                            <span>{item.location}</span>
                          </div>
                        </div>
                        <div className="text-right">
                          <p className="text-sm font-bold text-orange-600">{item.current}</p>
                          <p className="text-xs text-muted-foreground">of {item.minimum}</p>
                        </div>
                      </div>
                    ))}
                    <Button variant="outline" size="sm" className="w-full">
                      View All Alerts
                    </Button>
                  </CardContent>
                </Card>

                {/* Recent Activity */}
                <Card className="animate-slide-up" style={{ animationDelay: '0.2s' }}>
                  <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                      <Activity className="h-5 w-5" />
                      Recent Activity
                    </CardTitle>
                    <CardDescription>Latest warehouse activities</CardDescription>
                  </CardHeader>
                  <CardContent className="space-y-4">
                    {activities.map((activity, index) => (
                      <div key={index} className="flex items-start gap-3">
                        <div className={`mt-1 h-2 w-2 rounded-full ${
                          activity.type === 'pick' ? 'bg-blue-500' :
                          activity.type === 'receive' ? 'bg-green-500' :
                          activity.type === 'ship' ? 'bg-purple-500' :
                          'bg-orange-500'
                        }`} />
                        <div className="space-y-1 flex-1">
                          <p className="text-sm">{activity.description}</p>
                          <div className="flex items-center justify-between text-xs text-muted-foreground">
                            <span>{activity.user}</span>
                            <span>{activity.time}</span>
                          </div>
                        </div>
                      </div>
                    ))}
                    <Button variant="outline" size="sm" className="w-full">
                      View Activity Log
                    </Button>
                  </CardContent>
                </Card>
              </div>
            </div>

            {/* Performance Overview */}
            <Card className="animate-slide-up" style={{ animationDelay: '0.3s' }}>
              <CardHeader>
                <CardTitle>Performance Overview</CardTitle>
                <CardDescription>Key operational metrics and warehouse efficiency indicators</CardDescription>
              </CardHeader>
              <CardContent>
                <div className="grid gap-6 md:grid-cols-4">
                  <div className="space-y-2">
                    <div className="flex items-center justify-between">
                      <span className="text-sm font-medium">Pick Rate</span>
                      <span className="text-sm text-muted-foreground">{dashboardStats.performance.pickRate}%</span>
                    </div>
                    <div className="w-full bg-secondary rounded-full h-2">
                      <div className="bg-blue-500 h-2 rounded-full" style={{ width: `${dashboardStats.performance.pickRate}%` }} />
                    </div>
                  </div>
                  <div className="space-y-2">
                    <div className="flex items-center justify-between">
                      <span className="text-sm font-medium">Accuracy</span>
                      <span className="text-sm text-muted-foreground">{dashboardStats.performance.accuracy}%</span>
                    </div>
                    <div className="w-full bg-secondary rounded-full h-2">
                      <div className="bg-green-500 h-2 rounded-full" style={{ width: `${dashboardStats.performance.accuracy}%` }} />
                    </div>
                  </div>
                  <div className="space-y-2">
                    <div className="flex items-center justify-between">
                      <span className="text-sm font-medium">On-Time Delivery</span>
                      <span className="text-sm text-muted-foreground">{dashboardStats.performance.onTime}%</span>
                    </div>
                    <div className="w-full bg-secondary rounded-full h-2">
                      <div className="bg-purple-500 h-2 rounded-full" style={{ width: `${dashboardStats.performance.onTime}%` }} />
                    </div>
                  </div>
                  <div className="space-y-2">
                    <div className="flex items-center justify-between">
                      <span className="text-sm font-medium">Utilization</span>
                      <span className="text-sm text-muted-foreground">{dashboardStats.performance.utilization}%</span>
                    </div>
                    <div className="w-full bg-secondary rounded-full h-2">
                      <div className="bg-orange-500 h-2 rounded-full" style={{ width: `${dashboardStats.performance.utilization}%` }} />
                    </div>
                  </div>
                </div>
              </CardContent>
            </Card>
          </main>
        </div>
      </div>
    </SidebarProvider>
  )
}
