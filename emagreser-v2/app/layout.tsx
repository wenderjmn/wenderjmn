import type { Metadata } from "next";
import "./globals.css";

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
    <html lang="pt-BR" className="h-full">
      <body className="min-h-full">{children}</body>
    </html>
  );
}
