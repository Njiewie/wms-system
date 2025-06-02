import type { Metadata } from "next";
import { Inter } from "next/font/google";
import "./globals.css";

const inter = Inter({ subsets: ["latin"] });

export const metadata: Metadata = {
  title: "ECWMS - Enterprise Warehouse Management System",
  description: "Professional warehouse management solution with advanced inventory tracking, order processing, and analytics",
  keywords: "warehouse management, inventory, logistics, WMS, enterprise",
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en" className="h-full">
      <body className={`${inter.className} h-full bg-background antialiased`}>
        {children}
      </body>
    </html>
  );
}
