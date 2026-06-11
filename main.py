import os
import logging
import asyncio
from flask import Flask, request
from telegram import Update
from telegram.ext import Application, CommandHandler, MessageHandler, filters, ContextTypes

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = Flask(__name__)
TOKEN = os.environ.get("TELEGRAM_BOT_TOKEN")

# ساخت Application
application = Application.builder().token(TOKEN).build()

# هندلرها
async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text("👋 سلام! من بات اکو هستم.\nهر پیامی بفرستی برات تکرار می‌کنم.")

async def help_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text("دستورات:\n/start\n/help\n\nهر متنی بفرست.")

async def echo(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text(update.message.text)

application.add_handler(CommandHandler("start", start))
application.add_handler(CommandHandler("help", help_command))
application.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, echo))

@app.route("/")
def index():
    return "✅ Bot is running on Railway!"

@app.route("/webhook", methods=["POST"])
async def webhook():
    try:
        json_data = request.get_json(force=True)
        update = Update.de_json(json_data, application.bot)
        
        # Initialize if needed
        if not application.bot.bot:
            await application.initialize()
        
        await application.process_update(update)
        return "OK", 200
    except Exception as e:
        logger.error(f"Webhook error: {e}")
        return "Error", 500

if __name__ == "__main__":
    port = int(os.environ.get("PORT", 8080))
    logger.info(f"🚀 Bot starting on port {port}")
    
    # Initialize application
    asyncio.run(application.initialize())
    asyncio.run(application.start())
    
    app.run(host="0.0.0.0", port=port)
