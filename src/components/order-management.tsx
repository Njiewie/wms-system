"use client"

import { useState } from "react"
import {
  Package,
  Truck,
  Clock,
  CheckCircle,
  AlertCircle,
  ArrowRight,
  Plus,
  Filter,
  Search,
  Download,
  Eye,
  Edit,
  MoreHorizontal,
  User,
  MapPin,
  Calendar,
  RefreshCw,
  PlayCircle,
  PauseCircle,
  XCircle,
  ShippingBox,
  Workflow,
  Trash2,
  Save,
  X,
  Barcode,
  Calculator,
  Package2
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger, DropdownMenuSeparator } from "@/components/ui/dropdown-menu"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Separator } from "@/components/ui/separator"

// Mock SKU data for lookup
const skuMasterData = [
  {
    id: "SKU-001",
    sku: "PRD-ABC-123",
    description: "Premium Widget Assembly",
    dimensions: "10x8x6 cm",
    weight: "0.5 kg",
    unitCost: 45.99,
    category: "Electronics",
    available: 1165,
    reorderLevel: 100,
    clients: ["TechCorp", "InnovativeTech"],
    barcode: "1234567890123"
  },
  {
    id: "SKU-002",
    sku: "PRD-DEF-456",
    description: "Standard Component Set",
    dimensions: "15x12x8 cm",
    weight: "1.2 kg",
    unitCost: 32.75,
    category: "Components",
    available: 890,
    reorderLevel: 200,
    clients: ["TechCorp", "ManufacturingCorp"],
    barcode: "2345678901234"
  },
  {
    id: "SKU-003",
    sku: "PRD-GHI-789",
    description: "Specialized Tool Kit",
    dimensions: "25x20x10 cm",
    weight: "2.8 kg",
    unitCost: 89.99,
    category: "Tools",
    available: 345,
    reorderLevel: 50,
    clients: ["QuickFix", "TechCorp"],
    barcode: "3456789012345"
  }
]

// Mock clients data
const clientsData = [
  { id: "CLI-001", name: "TechCorp", code: "TECH" },
  { id: "CLI-002", name: "InnovativeTech", code: "INNO" },
  { id: "CLI-003", name: "ManufacturingCorp", code: "MANU" },
  { id: "CLI-004", name: "QuickFix", code: "QFIX" }
]

// Mock carriers data
const carriersData = [
  { id: "CAR-001", name: "FedEx", code: "FEDEX" },
  { id: "CAR-002", name: "UPS", code: "UPS" },
  { id: "CAR-003", name: "DHL", code: "DHL" },
  { id: "CAR-004", name: "USPS", code: "USPS" }
]

// Mock order data
const ordersData = [
  {
    id: "ORD-2024-001",
    orderNumber: "WMS-240115-001",
    sku: "PRD-ABC-123",
    description: "Premium Widget Assembly",
    quantity: 50,
    client: "TechCorp",
    clientId: "CLI-001",
    status: "PICKED",
    priority: "HIGH",
    created: "2024-01-15T10:30:00",
    dueDate: "2024-01-16T16:00:00",
    carrier: "FedEx",
    trackingNumber: "1234567890",
    deliveryAddress: "123 Tech Street, Silicon Valley, CA 94105",
    notes: "Handle with care - fragile components",
    allocatedAt: "2024-01-15T11:00:00",
    pickedAt: "2024-01-15T14:30:00",
    estimatedValue: 2299.50
  },
  {
    id: "ORD-2024-002",
    orderNumber: "WMS-240115-002",
    sku: "PRD-XYZ-789",
    description: "Standard Component Module",
    quantity: 25,
    client: "RetailMax",
    clientId: "CLI-002",
    status: "ALLOCATED",
    priority: "MEDIUM",
    created: "2024-01-15T08:15:00",
    dueDate: "2024-01-17T12:00:00",
    carrier: "UPS",
    trackingNumber: "",
    deliveryAddress: "456 Retail Blvd, Commerce City, TX 75201",
    notes: "Standard delivery",
    allocatedAt: "2024-01-15T09:00:00",
    pickedAt: null,
    estimatedValue: 737.50
  },
  {
    id: "ORD-2024-003",
    orderNumber: "WMS-240115-003",
    sku: "PRD-DEF-456",
    description: "Critical Safety Device",
    quantity: 100,
    client: "SafetyFirst",
    clientId: "CLI-003",
    status: "RELEASED",
    priority: "LOW",
    created: "2024-01-15T06:45:00",
    dueDate: "2024-01-18T10:00:00",
    carrier: "DHL",
    trackingNumber: "",
    deliveryAddress: "789 Safety Ave, Protection City, FL 33101",
    notes: "Requires special handling certification",
    allocatedAt: null,
    pickedAt: null,
    estimatedValue: 12500.00
  },
  {
    id: "ORD-2024-004",
    orderNumber: "WMS-240115-004",
    sku: "PRD-GHI-012",
    description: "Urgent Repair Part",
    quantity: 75,
    client: "QuickFix",
    clientId: "CLI-004",
    status: "HOLD",
    priority: "HIGH",
    created: "2024-01-15T09:20:00",
    dueDate: "2024-01-15T18:00:00",
    carrier: "Express Courier",
    trackingNumber: "",
    deliveryAddress: "321 Emergency Rd, Urgent City, NY 10001",
    notes: "Customer on hold - payment verification required",
    allocatedAt: null,
    pickedAt: null,
    estimatedValue: 1181.25
  },
  {
    id: "ORD-2024-005",
    orderNumber: "WMS-240115-005",
    sku: "PRD-JKL-345",
    description: "Completed Shipment",
    quantity: 30,
    client: "FinishedGoods Co",
    clientId: "CLI-005",
    status: "SHIPPED",
    priority: "MEDIUM",
    created: "2024-01-14T14:00:00",
    dueDate: "2024-01-15T12:00:00",
    carrier: "USPS",
    trackingNumber: "9405511899560123456789",
    deliveryAddress: "654 Completion St, Done City, WA 98101",
    notes: "Successfully delivered",
    allocatedAt: "2024-01-14T15:00:00",
    pickedAt: "2024-01-14T16:30:00",
    estimatedValue: 472.50
  }
]

const orderStats = {
  total: 156,
  hold: 7,
  released: 34,
  allocated: 45,
  picked: 39,
  shipped: 31,
  totalValue: 245670.00,
  avgProcessingTime: "2.4 hours"
}

function StatusBadge({ status }: { status: string }) {
  const configs = {
    HOLD: { bg: "bg-red-100 dark:bg-red-900/20", text: "text-red-800 dark:text-red-400", icon: <PauseCircle className="h-3 w-3" /> },
    RELEASED: { bg: "bg-yellow-100 dark:bg-yellow-900/20", text: "text-yellow-800 dark:text-yellow-400", icon: <PlayCircle className="h-3 w-3" /> },
    ALLOCATED: { bg: "bg-blue-100 dark:bg-blue-900/20", text: "text-blue-800 dark:text-blue-400", icon: <Package className="h-3 w-3" /> },
    PICKED: { bg: "bg-purple-100 dark:bg-purple-900/20", text: "text-purple-800 dark:text-purple-400", icon: <CheckCircle className="h-3 w-3" /> },
    SHIPPED: { bg: "bg-green-100 dark:bg-green-900/20", text: "text-green-800 dark:text-green-400", icon: <Truck className="h-3 w-3" /> }
  }

  const config = configs[status as keyof typeof configs] || configs.HOLD

  return (
    <Badge className={`${config.bg} ${config.text} flex items-center gap-1`}>
      {config.icon}
      {status}
    </Badge>
  )
}

function PriorityBadge({ priority }: { priority: string }) {
  const configs = {
    HIGH: { bg: "bg-red-500", text: "text-white" },
    MEDIUM: { bg: "bg-yellow-500", text: "text-white" },
    LOW: { bg: "bg-green-500", text: "text-white" }
  }

  const config = configs[priority as keyof typeof configs] || configs.MEDIUM

  return (
    <Badge className={`${config.bg} ${config.text}`}>
      {priority}
    </Badge>
  )
}

function OrderWorkflow({ status }: { status: string }) {
  const steps = ["HOLD", "RELEASED", "ALLOCATED", "PICKED", "SHIPPED"]
  const currentIndex = steps.indexOf(status)

  return (
    <div className="flex items-center gap-2 p-3 bg-muted/30 rounded-lg">
      {steps.map((step, index) => {
        const isActive = index === currentIndex
        const isCompleted = index < currentIndex
        const isUpcoming = index > currentIndex

        return (
          <div key={step} className="flex items-center">
            <div className={`
              flex items-center justify-center w-8 h-8 rounded-full text-xs font-medium
              ${isCompleted ? 'bg-green-500 text-white' :
                isActive ? 'bg-blue-500 text-white' :
                'bg-gray-200 text-gray-500'}
            `}>
              {isCompleted ? <CheckCircle className="h-4 w-4" /> : index + 1}
            </div>
            {index < steps.length - 1 && (
              <ArrowRight className={`h-4 w-4 mx-2 ${
                isCompleted ? 'text-green-500' : 'text-gray-300'
              }`} />
            )}
          </div>
        )
      })}
    </div>
  )
}

function OrderDetailsDialog({ order }: { order: typeof ordersData[0] }) {
  return (
    <Dialog>
      <DialogTrigger asChild>
        <Button variant="ghost" size="icon">
          <Eye className="h-4 w-4" />
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Order Details - {order.orderNumber}</DialogTitle>
          <DialogDescription>
            Complete order information and processing history
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-6">
          {/* Order Status Workflow */}
          <div>
            <h3 className="text-lg font-semibold mb-3">Order Workflow</h3>
            <OrderWorkflow status={order.status} />
          </div>

          {/* Order Information Grid */}
          <div className="grid gap-6 md:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle className="text-base">Order Information</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Order Number:</span>
                  <span className="font-mono">{order.orderNumber}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">SKU:</span>
                  <span className="font-mono">{order.sku}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Description:</span>
                  <span>{order.description}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Quantity:</span>
                  <span className="font-semibold">{order.quantity}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Status:</span>
                  <StatusBadge status={order.status} />
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Priority:</span>
                  <PriorityBadge priority={order.priority} />
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Estimated Value:</span>
                  <span className="font-semibold">${order.estimatedValue.toFixed(2)}</span>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-base">Client & Delivery</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Client:</span>
                  <span>{order.client}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Client ID:</span>
                  <span className="font-mono">{order.clientId}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Carrier:</span>
                  <span>{order.carrier}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Tracking:</span>
                  <span className="font-mono">{order.trackingNumber || "Not assigned"}</span>
                </div>
                <div>
                  <span className="text-muted-foreground">Delivery Address:</span>
                  <p className="mt-1 text-sm">{order.deliveryAddress}</p>
                </div>
              </CardContent>
            </Card>
          </div>

          {/* Timeline */}
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Processing Timeline</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                <div className="flex items-center gap-3 p-3 rounded-lg bg-blue-50 dark:bg-blue-950/20">
                  <Clock className="h-4 w-4 text-blue-500" />
                  <div>
                    <p className="font-medium">Order Created</p>
                    <p className="text-sm text-muted-foreground">
                      {new Date(order.created).toLocaleString()}
                    </p>
                  </div>
                </div>

                {order.allocatedAt && (
                  <div className="flex items-center gap-3 p-3 rounded-lg bg-yellow-50 dark:bg-yellow-950/20">
                    <Package className="h-4 w-4 text-yellow-500" />
                    <div>
                      <p className="font-medium">Inventory Allocated</p>
                      <p className="text-sm text-muted-foreground">
                        {new Date(order.allocatedAt).toLocaleString()}
                      </p>
                    </div>
                  </div>
                )}

                {order.pickedAt && (
                  <div className="flex items-center gap-3 p-3 rounded-lg bg-purple-50 dark:bg-purple-950/20">
                    <CheckCircle className="h-4 w-4 text-purple-500" />
                    <div>
                      <p className="font-medium">Order Picked</p>
                      <p className="text-sm text-muted-foreground">
                        {new Date(order.pickedAt).toLocaleString()}
                      </p>
                    </div>
                  </div>
                )}

                {order.status === "SHIPPED" && (
                  <div className="flex items-center gap-3 p-3 rounded-lg bg-green-50 dark:bg-green-950/20">
                    <Truck className="h-4 w-4 text-green-500" />
                    <div>
                      <p className="font-medium">Order Shipped</p>
                      <p className="text-sm text-muted-foreground">
                        Tracking: {order.trackingNumber}
                      </p>
                    </div>
                  </div>
                )}
              </div>
            </CardContent>
          </Card>

          {/* Notes */}
          {order.notes && (
            <Card>
              <CardHeader>
                <CardTitle className="text-base">Order Notes</CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-sm">{order.notes}</p>
              </CardContent>
            </Card>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}

// Order Line interface
interface OrderLine {
  id: string
  sku: string
  description: string
  quantity: number
  unitCost: number
  totalCost: number
  available: number
  notes?: string
}

// Enhanced Order Creation Dialog with SKU lookup and line management
function CreateOrderDialog() {
  const [open, setOpen] = useState(false)
  const [currentStep, setCurrentStep] = useState(1)
  const [orderData, setOrderData] = useState({
    orderNumber: '',
    client: '',
    priority: 'MEDIUM',
    dueDate: '',
    carrier: '',
    deliveryAddress: '',
    notes: ''
  })
  const [orderLines, setOrderLines] = useState<OrderLine[]>([])
  const [skuSearch, setSkuSearch] = useState('')
  const [selectedSku, setSelectedSku] = useState<typeof skuMasterData[0] | null>(null)
  const [lineQuantity, setLineQuantity] = useState('')
  const [lineNotes, setLineNotes] = useState('')

  // Filter SKUs based on search and selected client
  const filteredSkus = skuMasterData.filter(sku =>
    sku.sku.toLowerCase().includes(skuSearch.toLowerCase()) ||
    sku.description.toLowerCase().includes(skuSearch.toLowerCase())
  ).filter(sku =>
    !orderData.client || sku.clients.includes(clientsData.find(c => c.id === orderData.client)?.name || '')
  )

  const addOrderLine = () => {
    if (!selectedSku || !lineQuantity || Number(lineQuantity) <= 0) return

    const quantity = Number(lineQuantity)
    const newLine: OrderLine = {
      id: `LINE-${Date.now()}`,
      sku: selectedSku.sku,
      description: selectedSku.description,
      quantity,
      unitCost: selectedSku.unitCost,
      totalCost: quantity * selectedSku.unitCost,
      available: selectedSku.available,
      notes: lineNotes
    }

    setOrderLines([...orderLines, newLine])
    setSelectedSku(null)
    setSkuSearch('')
    setLineQuantity('')
    setLineNotes('')
  }

  const removeOrderLine = (lineId: string) => {
    setOrderLines(orderLines.filter(line => line.id !== lineId))
  }

  const calculateOrderTotal = () => {
    return orderLines.reduce((total, line) => total + line.totalCost, 0)
  }

  const resetForm = () => {
    setCurrentStep(1)
    setOrderData({
      orderNumber: '',
      client: '',
      priority: 'MEDIUM',
      dueDate: '',
      carrier: '',
      deliveryAddress: '',
      notes: ''
    })
    setOrderLines([])
    setSelectedSku(null)
    setSkuSearch('')
    setLineQuantity('')
    setLineNotes('')
  }

  const createOrder = () => {
    // Here you would typically send the order data to your backend
    console.log('Creating order:', { orderData, orderLines, total: calculateOrderTotal() })
    setOpen(false)
    resetForm()
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button className="bg-blue-600 hover:bg-blue-700">
          <Plus className="mr-2 h-4 w-4" />
          Create Outbound Order
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-6xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Create New Outbound Order</DialogTitle>
          <DialogDescription>
            Step-by-step order creation with SKU validation and inventory checking
          </DialogDescription>
        </DialogHeader>

        {/* Progress Indicator */}
        <div className="flex items-center justify-center space-x-4 mb-6">
          {[1, 2, 3].map((step) => (
            <div key={step} className="flex items-center">
              <div className={`
                flex items-center justify-center w-8 h-8 rounded-full text-sm font-medium
                ${currentStep >= step ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-500'}
              `}>
                {step}
              </div>
              {step < 3 && (
                <ArrowRight className={`h-4 w-4 mx-2 ${
                  currentStep > step ? 'text-blue-500' : 'text-gray-300'
                }`} />
              )}
            </div>
          ))}
        </div>

        {/* Step 1: Order Header */}
        {currentStep === 1 && (
          <div className="space-y-6">
            <div className="grid gap-4 md:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="orderNumber">Order Number</Label>
                <Input
                  id="orderNumber"
                  placeholder="Auto-generated if empty"
                  value={orderData.orderNumber}
                  onChange={(e) => setOrderData({...orderData, orderNumber: e.target.value})}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="client">Client *</Label>
                <Select value={orderData.client} onValueChange={(value) => setOrderData({...orderData, client: value})}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select client" />
                  </SelectTrigger>
                  <SelectContent>
                    {clientsData.map((client) => (
                      <SelectItem key={client.id} value={client.id}>
                        {client.name} ({client.code})
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="priority">Priority</Label>
                <Select value={orderData.priority} onValueChange={(value) => setOrderData({...orderData, priority: value})}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="LOW">Low</SelectItem>
                    <SelectItem value="MEDIUM">Medium</SelectItem>
                    <SelectItem value="HIGH">High</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="dueDate">Due Date *</Label>
                <Input
                  id="dueDate"
                  type="datetime-local"
                  value={orderData.dueDate}
                  onChange={(e) => setOrderData({...orderData, dueDate: e.target.value})}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="carrier">Carrier</Label>
                <Select value={orderData.carrier} onValueChange={(value) => setOrderData({...orderData, carrier: value})}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select carrier" />
                  </SelectTrigger>
                  <SelectContent>
                    {carriersData.map((carrier) => (
                      <SelectItem key={carrier.id} value={carrier.id}>
                        {carrier.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="deliveryAddress">Delivery Address *</Label>
              <Input
                id="deliveryAddress"
                placeholder="Complete delivery address"
                value={orderData.deliveryAddress}
                onChange={(e) => setOrderData({...orderData, deliveryAddress: e.target.value})}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="notes">Order Notes</Label>
              <Input
                id="notes"
                placeholder="Special instructions or notes"
                value={orderData.notes}
                onChange={(e) => setOrderData({...orderData, notes: e.target.value})}
              />
            </div>
            <div className="flex justify-end">
              <Button
                onClick={() => setCurrentStep(2)}
                disabled={!orderData.client || !orderData.dueDate || !orderData.deliveryAddress}
              >
                Next: Add Order Lines
                <ArrowRight className="ml-2 h-4 w-4" />
              </Button>
            </div>
          </div>
        )}

        {/* Step 2: Order Lines */}
        {currentStep === 2 && (
          <div className="space-y-6">
            {/* SKU Lookup Section */}
            <Card>
              <CardHeader>
                <CardTitle className="text-lg">Add Order Line</CardTitle>
                <CardDescription>Search and select SKUs to add to this order</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid gap-4 md:grid-cols-3">
                  <div className="space-y-2">
                    <Label htmlFor="skuSearch">SKU Search</Label>
                    <div className="relative">
                      <Search className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
                      <Input
                        id="skuSearch"
                        placeholder="Search by SKU or description"
                        value={skuSearch}
                        onChange={(e) => setSkuSearch(e.target.value)}
                        className="pl-10"
                      />
                    </div>
                    {skuSearch && filteredSkus.length > 0 && (
                      <div className="border rounded-md max-h-40 overflow-y-auto">
                        {filteredSkus.map((sku) => (
                          <div
                            key={sku.id}
                            className="p-2 hover:bg-muted cursor-pointer border-b last:border-b-0"
                            onClick={() => {
                              setSelectedSku(sku)
                              setSkuSearch(sku.sku)
                            }}
                          >
                            <div className="font-medium">{sku.sku}</div>
                            <div className="text-sm text-muted-foreground">{sku.description}</div>
                            <div className="text-xs text-green-600">Available: {sku.available}</div>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="quantity">Quantity</Label>
                    <Input
                      id="quantity"
                      type="number"
                      placeholder="Enter quantity"
                      value={lineQuantity}
                      onChange={(e) => setLineQuantity(e.target.value)}
                      min="1"
                      max={selectedSku?.available || 999999}
                    />
                    {selectedSku && Number(lineQuantity) > selectedSku.available && (
                      <p className="text-sm text-red-600">
                        ⚠️ Quantity exceeds available stock ({selectedSku.available})
                      </p>
                    )}
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="lineNotes">Line Notes</Label>
                    <Input
                      id="lineNotes"
                      placeholder="Optional line notes"
                      value={lineNotes}
                      onChange={(e) => setLineNotes(e.target.value)}
                    />
                  </div>
                </div>

                {/* Selected SKU Details */}
                {selectedSku && (
                  <Card className="bg-blue-50 dark:bg-blue-950/20">
                    <CardContent className="pt-4">
                      <div className="grid gap-2 md:grid-cols-4">
                        <div>
                          <div className="text-sm font-medium">SKU</div>
                          <div className="font-mono">{selectedSku.sku}</div>
                        </div>
                        <div>
                          <div className="text-sm font-medium">Unit Cost</div>
                          <div>${selectedSku.unitCost.toFixed(2)}</div>
                        </div>
                        <div>
                          <div className="text-sm font-medium">Available</div>
                          <div className="text-green-600">{selectedSku.available}</div>
                        </div>
                        <div>
                          <div className="text-sm font-medium">Line Total</div>
                          <div className="font-semibold">
                            ${lineQuantity ? (Number(lineQuantity) * selectedSku.unitCost).toFixed(2) : '0.00'}
                          </div>
                        </div>
                      </div>
                      <div className="mt-2">
                        <div className="text-sm font-medium">Description</div>
                        <div>{selectedSku.description}</div>
                      </div>
                    </CardContent>
                  </Card>
                )}

                <Button
                  onClick={addOrderLine}
                  disabled={!selectedSku || !lineQuantity || Number(lineQuantity) <= 0}
                  className="w-full"
                >
                  <Plus className="mr-2 h-4 w-4" />
                  Add Line to Order
                </Button>
              </CardContent>
            </Card>

            {/* Order Lines List */}
            {orderLines.length > 0 && (
              <Card>
                <CardHeader>
                  <CardTitle className="text-lg">Order Lines ({orderLines.length})</CardTitle>
                  <CardDescription>Review and manage order lines</CardDescription>
                </CardHeader>
                <CardContent>
                  <div className="space-y-2">
                    {orderLines.map((line) => (
                      <div key={line.id} className="flex items-center justify-between p-3 border rounded-lg">
                        <div className="flex-1">
                          <div className="flex items-center gap-4">
                            <div>
                              <div className="font-medium">{line.sku}</div>
                              <div className="text-sm text-muted-foreground">{line.description}</div>
                            </div>
                            <div className="text-center">
                              <div className="text-sm font-medium">Qty: {line.quantity}</div>
                              <div className="text-xs text-muted-foreground">
                                Available: {line.available}
                              </div>
                            </div>
                            <div className="text-center">
                              <div className="text-sm font-medium">${line.unitCost.toFixed(2)}</div>
                              <div className="text-xs text-muted-foreground">per unit</div>
                            </div>
                            <div className="text-center">
                              <div className="font-semibold">${line.totalCost.toFixed(2)}</div>
                              <div className="text-xs text-muted-foreground">line total</div>
                            </div>
                            {line.notes && (
                              <div className="text-sm text-muted-foreground max-w-xs truncate">
                                📝 {line.notes}
                              </div>
                            )}
                          </div>
                        </div>
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() => removeOrderLine(line.id)}
                          className="text-red-600 hover:text-red-700"
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </div>
                    ))}
                  </div>
                  <Separator className="my-4" />
                  <div className="flex justify-between items-center">
                    <div className="text-lg font-semibold">
                      Order Total: ${calculateOrderTotal().toFixed(2)}
                    </div>
                    <div className="text-sm text-muted-foreground">
                      {orderLines.length} line{orderLines.length !== 1 ? 's' : ''}
                    </div>
                  </div>
                </CardContent>
              </Card>
            )}

            <div className="flex justify-between">
              <Button variant="outline" onClick={() => setCurrentStep(1)}>
                <ArrowRight className="mr-2 h-4 w-4 rotate-180" />
                Back
              </Button>
              <Button
                onClick={() => setCurrentStep(3)}
                disabled={orderLines.length === 0}
              >
                Next: Review & Create
                <ArrowRight className="ml-2 h-4 w-4" />
              </Button>
            </div>
          </div>
        )}

        {/* Step 3: Review & Create */}
        {currentStep === 3 && (
          <div className="space-y-6">
            <Card>
              <CardHeader>
                <CardTitle className="text-lg">Order Review</CardTitle>
                <CardDescription>Review order details before creation</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid gap-4 md:grid-cols-2">
                  <div>
                    <div className="text-sm font-medium text-muted-foreground">Client</div>
                    <div>{clientsData.find(c => c.id === orderData.client)?.name}</div>
                  </div>
                  <div>
                    <div className="text-sm font-medium text-muted-foreground">Priority</div>
                    <PriorityBadge priority={orderData.priority} />
                  </div>
                  <div>
                    <div className="text-sm font-medium text-muted-foreground">Due Date</div>
                    <div>{new Date(orderData.dueDate).toLocaleString()}</div>
                  </div>
                  <div>
                    <div className="text-sm font-medium text-muted-foreground">Carrier</div>
                    <div>{carriersData.find(c => c.id === orderData.carrier)?.name || 'Not selected'}</div>
                  </div>
                </div>
                <div>
                  <div className="text-sm font-medium text-muted-foreground">Delivery Address</div>
                  <div>{orderData.deliveryAddress}</div>
                </div>
                {orderData.notes && (
                  <div>
                    <div className="text-sm font-medium text-muted-foreground">Notes</div>
                    <div>{orderData.notes}</div>
                  </div>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-lg">Order Lines Summary</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="space-y-2">
                  {orderLines.map((line) => (
                    <div key={line.id} className="flex justify-between items-center p-2 border rounded">
                      <div>
                        <div className="font-medium">{line.sku}</div>
                        <div className="text-sm text-muted-foreground">{line.description}</div>
                      </div>
                      <div className="text-right">
                        <div className="font-medium">{line.quantity} × ${line.unitCost.toFixed(2)}</div>
                        <div className="text-sm font-semibold">${line.totalCost.toFixed(2)}</div>
                      </div>
                    </div>
                  ))}
                </div>
                <Separator className="my-4" />
                <div className="flex justify-between items-center text-lg font-bold">
                  <span>Total Order Value:</span>
                  <span>${calculateOrderTotal().toFixed(2)}</span>
                </div>
              </CardContent>
            </Card>

            <div className="flex justify-between">
              <Button variant="outline" onClick={() => setCurrentStep(2)}>
                <ArrowRight className="mr-2 h-4 w-4 rotate-180" />
                Back
              </Button>
              <div className="flex gap-2">
                <Button variant="outline" onClick={() => setOpen(false)}>
                  Cancel
                </Button>
                <Button onClick={createOrder} className="bg-green-600 hover:bg-green-700">
                  <Save className="mr-2 h-4 w-4" />
                  Create Order
                </Button>
              </div>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}

export function OrderManagement() {
  const [searchTerm, setSearchTerm] = useState("")
  const [statusFilter, setStatusFilter] = useState("all")
  const [priorityFilter, setPriorityFilter] = useState("all")
  const [selectedOrders, setSelectedOrders] = useState<string[]>([])

  const filteredOrders = ordersData.filter(order => {
    const matchesSearch =
      order.orderNumber.toLowerCase().includes(searchTerm.toLowerCase()) ||
      order.sku.toLowerCase().includes(searchTerm.toLowerCase()) ||
      order.client.toLowerCase().includes(searchTerm.toLowerCase())

    const matchesStatus = statusFilter === "all" || order.status === statusFilter
    const matchesPriority = priorityFilter === "all" || order.priority === priorityFilter

    return matchesSearch && matchesStatus && matchesPriority
  })

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="wms-page-header">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold text-foreground">Order Management</h1>
            <p className="text-muted-foreground">Track and manage outbound orders through their complete lifecycle</p>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" className="gap-2">
              <Download className="h-4 w-4" />
              Export Orders
            </Button>
            <CreateOrderDialog />
          </div>
        </div>
      </div>

      {/* Stats Overview */}
      <div className="grid gap-4 md:grid-cols-6">
        <Card className="animate-fade-in">
          <CardContent className="p-4">
            <div className="flex items-center gap-2">
              <Package className="h-4 w-4 text-blue-500" />
              <div>
                <p className="text-sm text-muted-foreground">Total Orders</p>
                <p className="text-lg font-bold">{orderStats.total}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className="animate-fade-in" style={{ animationDelay: '0.1s' }}>
          <CardContent className="p-4">
            <div className="flex items-center gap-2">
              <PauseCircle className="h-4 w-4 text-red-500" />
              <div>
                <p className="text-sm text-muted-foreground">On Hold</p>
                <p className="text-lg font-bold text-red-600">{orderStats.hold}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className="animate-fade-in" style={{ animationDelay: '0.2s' }}>
          <CardContent className="p-4">
            <div className="flex items-center gap-2">
              <PlayCircle className="h-4 w-4 text-yellow-500" />
              <div>
                <p className="text-sm text-muted-foreground">Released</p>
                <p className="text-lg font-bold text-yellow-600">{orderStats.released}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className="animate-fade-in" style={{ animationDelay: '0.3s' }}>
          <CardContent className="p-4">
            <div className="flex items-center gap-2">
              <CheckCircle className="h-4 w-4 text-purple-500" />
              <div>
                <p className="text-sm text-muted-foreground">Picked</p>
                <p className="text-lg font-bold text-purple-600">{orderStats.picked}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className="animate-fade-in" style={{ animationDelay: '0.4s' }}>
          <CardContent className="p-4">
            <div className="flex items-center gap-2">
              <Truck className="h-4 w-4 text-green-500" />
              <div>
                <p className="text-sm text-muted-foreground">Shipped</p>
                <p className="text-lg font-bold text-green-600">{orderStats.shipped}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className="animate-fade-in" style={{ animationDelay: '0.5s' }}>
          <CardContent className="p-4">
            <div className="flex items-center gap-2">
              <Clock className="h-4 w-4 text-gray-500" />
              <div>
                <p className="text-sm text-muted-foreground">Avg Process Time</p>
                <p className="text-lg font-bold">{orderStats.avgProcessingTime}</p>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Filters */}
      <Card className="animate-slide-up">
        <CardContent className="p-4">
          <div className="flex flex-wrap items-center gap-4">
            <div className="flex-1 min-w-64">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  placeholder="Search by order number, SKU, or client..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="pl-10"
                />
              </div>
            </div>

            <div className="flex items-center gap-2">
              <label className="text-sm">Status:</label>
              <select
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
                className="rounded-md border border-input bg-background px-3 py-2 text-sm"
              >
                <option value="all">All Status</option>
                <option value="HOLD">Hold</option>
                <option value="RELEASED">Released</option>
                <option value="ALLOCATED">Allocated</option>
                <option value="PICKED">Picked</option>
                <option value="SHIPPED">Shipped</option>
              </select>
            </div>

            <div className="flex items-center gap-2">
              <label className="text-sm">Priority:</label>
              <select
                value={priorityFilter}
                onChange={(e) => setPriorityFilter(e.target.value)}
                className="rounded-md border border-input bg-background px-3 py-2 text-sm"
              >
                <option value="all">All Priority</option>
                <option value="HIGH">High</option>
                <option value="MEDIUM">Medium</option>
                <option value="LOW">Low</option>
              </select>
            </div>

            <Button variant="outline" size="sm" className="gap-2">
              <RefreshCw className="h-4 w-4" />
              Refresh
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Orders Table */}
      <Card className="animate-slide-up" style={{ animationDelay: '0.1s' }}>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle>Orders</CardTitle>
              <CardDescription>
                Showing {filteredOrders.length} of {ordersData.length} orders
              </CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent className="p-0">
          <div className="overflow-x-auto wms-scrollbar">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Order Number</TableHead>
                  <TableHead>SKU</TableHead>
                  <TableHead>Description</TableHead>
                  <TableHead>Qty</TableHead>
                  <TableHead>Client</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Priority</TableHead>
                  <TableHead>Created</TableHead>
                  <TableHead>Due Date</TableHead>
                  <TableHead>Carrier</TableHead>
                  <TableHead>Value</TableHead>
                  <TableHead />
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredOrders.map((order) => (
                  <TableRow key={order.id} className="wms-table-row">
                    <TableCell className="font-mono font-medium">{order.orderNumber}</TableCell>
                    <TableCell className="font-mono text-sm">{order.sku}</TableCell>
                    <TableCell className="max-w-48">
                      <div className="truncate" title={order.description}>
                        {order.description}
                      </div>
                    </TableCell>
                    <TableCell className="text-right font-medium">{order.quantity}</TableCell>
                    <TableCell>{order.client}</TableCell>
                    <TableCell>
                      <StatusBadge status={order.status} />
                    </TableCell>
                    <TableCell>
                      <PriorityBadge priority={order.priority} />
                    </TableCell>
                    <TableCell className="text-sm">
                      {new Date(order.created).toLocaleDateString()}
                    </TableCell>
                    <TableCell className="text-sm">
                      {new Date(order.dueDate).toLocaleDateString()}
                    </TableCell>
                    <TableCell>{order.carrier}</TableCell>
                    <TableCell className="text-right font-medium">
                      ${order.estimatedValue.toFixed(2)}
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center gap-1">
                        <OrderDetailsDialog order={order} />
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon">
                              <MoreHorizontal className="h-4 w-4" />
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            <DropdownMenuItem>
                              <Edit className="mr-2 h-4 w-4" />
                              Edit Order
                            </DropdownMenuItem>
                            <DropdownMenuItem>
                              <Package className="mr-2 h-4 w-4" />
                              Allocate Inventory
                            </DropdownMenuItem>
                            <DropdownMenuItem>
                              <CheckCircle className="mr-2 h-4 w-4" />
                              Mark as Picked
                            </DropdownMenuItem>
                            <DropdownMenuItem>
                              <Truck className="mr-2 h-4 w-4" />
                              Create Shipment
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem className="text-red-600">
                              <XCircle className="mr-2 h-4 w-4" />
                              Cancel Order
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
