require("dotenv").config();

const express = require("express");
const fs = require("fs");
const path = require("path");

const app = express();
const PORT = process.env.PORT || 3000;
const DATA_DIR = path.join(__dirname, "data");
const COTAS_FILE = path.join(DATA_DIR, "cotas.json");
const TOTAL_COTAS = 10000;

app.use(express.json());
app.use(express.static(__dirname));

function ensureDataFile() {
  if (!fs.existsSync(DATA_DIR)) {
    fs.mkdirSync(DATA_DIR, { recursive: true });
  }
  if (!fs.existsSync(COTAS_FILE)) {
    fs.writeFileSync(COTAS_FILE, JSON.stringify({ pagas: [], processando: [] }, null, 2));
  }
}

function readCotas() {
  ensureDataFile();
  return JSON.parse(fs.readFileSync(COTAS_FILE, "utf8"));
}

function writeCotas(data) {
  ensureDataFile();
  fs.writeFileSync(COTAS_FILE, JSON.stringify(data, null, 2));
}

function normalizeNumeros(numeros) {
  return [...new Set(numeros.map((n) => parseInt(n, 10)).filter((n) => n >= 1 && n <= TOTAL_COTAS))].sort(
    (a, b) => a - b
  );
}

app.get("/api/cotas", (_req, res) => {
  const data = readCotas();
  res.json({ pagas: data.pagas, processando: data.processando, total: TOTAL_COTAS });
});

app.post("/api/pix", async (req, res) => {
  const token = process.env.MERCADOPAGO_ACCESS_TOKEN;
  const { nome, cpf, email, whatsapp, numeros, valor } = req.body || {};

  if (!nome || !cpf || !email || !Array.isArray(numeros) || numeros.length === 0 || !valor) {
    return res.status(400).json({ status: "error", message: "Dados incompletos." });
  }

  const nums = normalizeNumeros(numeros);
  if (nums.length === 0) {
    return res.status(400).json({ status: "error", message: "Números inválidos." });
  }

  const cotas = readCotas();
  const ocupadas = nums.filter((n) => cotas.pagas.includes(n) || cotas.processando.includes(n));
  if (ocupadas.length > 0) {
    return res.status(409).json({
      status: "error",
      message: "Alguns números já estão reservados: " + ocupadas.join(", "),
    });
  }

  if (!token || token.includes("tu-token-aqui")) {
    cotas.processando = [...new Set([...cotas.processando, ...nums])];
    writeCotas(cotas);

    const demoCode =
      "00020126580014br.gov.bcb.pix0136demo-" +
      Date.now() +
      "520400005303986540" +
      String(valor).replace(".", "") +
      "5802BR5925Casa Dos Frios Sorteios6009SAO PAULO62070503***6304DEMO";

    return res.json({
      status: "ok",
      demo: true,
      message: "Modo demo: configure MERCADOPAGO_ACCESS_TOKEN no .env para pagamentos reais.",
      point_of_interaction: {
        transaction_data: { qr_code: demoCode },
      },
      transaction_amount: valor,
      numeros: nums,
    });
  }

  const partes = String(nome).trim().split(/\s+/);
  const firstName = partes[0];
  const lastName = partes.slice(1).join(" ") || "Silva";

  const payload = {
    transaction_amount: parseFloat(valor),
    description: "Cotas Casa Dos Frios - Números: " + nums.join(", "),
    payment_method_id: "pix",
    payer: {
      email: String(email).trim(),
      first_name: firstName,
      last_name: lastName,
      identification: {
        type: "CPF",
        number: String(cpf).replace(/\D/g, ""),
      },
    },
    metadata: {
      whatsapp: String(whatsapp || ""),
      numeros: nums.join(","),
    },
  };

  try {
    const mpRes = await fetch("https://api.mercadopago.com/v1/payments", {
      method: "POST",
      headers: {
        Authorization: "Bearer " + token,
        "Content-Type": "application/json",
        "X-Idempotency-Key": "key_" + Date.now() + "_" + Math.random().toString(36).slice(2),
      },
      body: JSON.stringify(payload),
    });

    const mpData = await mpRes.json();

    if (!mpRes.ok) {
      return res.status(mpRes.status).json({
        status: "error",
        message: mpData.message || "Erro ao gerar PIX no Mercado Pago.",
        details: mpData,
      });
    }

    cotas.processando = [...new Set([...cotas.processando, ...nums])];
    writeCotas(cotas);

    res.json(mpData);
  } catch (err) {
    res.status(500).json({ status: "error", message: "Falha na conexão com Mercado Pago." });
  }
});

app.listen(PORT, () => {
  ensureDataFile();
  console.log("Servidor rodando em http://localhost:" + PORT);
});
