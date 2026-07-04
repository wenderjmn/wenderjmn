import type { Metadata } from "next";
import { Inter } from "next/font/google";
import "./globals.css";

const inter = Inter({ subsets: ["latin"], variable: "--font-inter" });

export const metadata: Metadata = {
  title: "EmagreSer V2 — Programa de 12 Semanas",
  description: "Sua jornada de transformação com consciência e gentileza.",
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="pt-BR" className={`h-full ${inter.variable}`}>
      <body className={`min-h-full ${inter.className}`}>{children}</body>
    </html>
  );
}
