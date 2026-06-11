import os
import logging
import asyncio
from flask import Flask, request
from telegram import Update
from telegram.ext import Application, CommandHandler, MessageHandler, CallbackQueryHandler, filters, ContextTypes
from config import BOT_PASSWORD
from database import init_db, authenticate_user, is_user_authenticated, add_task, get_user_tasks, mark_task_done, delete_task

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = Flask(__name__)
TOKEN = os.environ.get("TELEGRAM_BOT_TOKEN")

application = Application.builder().token(TOKEN).build()

CATEGORIES = [
    "کارای سالن",
    "خریدای خونه",
    "کارای خونه",
    "خریدای سالن",
    "کارای من"
]

def get_main_keyboard():
    keyboard = [
        [InlineKeyboardButton("➕ افزودن کار جدید", callback_data="add_task")],
        [InlineKeyboardButton("✅ علامت زدن انجام شده", callback_data="mark_done_menu")],
        [InlineKeyboardButton("🔄 به‌روزرسانی لیست", callback_data="refresh")]
    ]
    return InlineKeyboardMarkup(keyboard)

def format_task_list(user_id):
    tasks = get_user_tasks(user_id)
    if not tasks:
        return "📝 لیست کارهای شما خالی است.\n\nبا دکمه پایین کار جدید اضافه کن."
    
    text = "📝 لیست کارهای شما\n\n"
    current_category = None
    
    for task in tasks:
        if task['category'] != current_category:
            if current_category is not None:
                text += "\n"
            current_category = task['category']
            text += f"🗂 {current_category}\n"
        
        status = "✅" if task['done'] else "🔲"
        text += f"{status} {task['text']}\n"
    
    return text

async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    
    if is_user_authenticated(user_id):
        text = format_task_list(user_id)
        await update.message.reply_text(text, reply_markup=get_main_keyboard())
        return
    
    context.user_data['waiting_for_password'] = True
    await update.message.reply_text("🔐 برای استفاده از ربات، لطفاً رمز عبور را وارد کن:")

async def password_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if context.user_data.get('waiting_for_password'):
        if update.message.text == BOT_PASSWORD:
            user_id = update.effective_user.id
            authenticate_user(user_id)
            context.user_data['authenticated'] = True
            context.user_data['waiting_for_password'] = False
            
            text = format_task_list(user_id)
            await update.message.reply_text("✅ رمز صحیح بود. خوش آمدی!")
            await update.message.reply_text(text, reply_markup=get_main_keyboard())
        else:
            await update.message.reply_text("❌ رمز اشتباه است. مجدحداً تلاش کن.")

async def button_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()
    user_id = query.from_user.id
    
    if query.data == "add_task":
        context.user_data['waiting_for_task_text'] = True
        await query.edit_message_text("لطفاً متن کار را بنویس:")
    
    elif query.data == "mark_done_menu":
        tasks = get_user_tasks(user_id)
        if not tasks:
            await query.edit_message_text("هیچ کاری برای انجام وجود ندارد.", reply_markup=get_main_keyboard())
            return
        
        keyboard = []
        for task in tasks:
            keyboard.append([InlineKeyboardButton(f"🔲 {task['text'][:40]}", callback_data=f"confirm_done_{task['id']}")])
        keyboard.append([InlineKeyboardButton("➠ بازگشت", callback_data="back_to_main")])
        
        await query.edit_message_text("کدام کار را انجام شده می‌دانی؟", reply_markup=InlineKeyboardMarkup(keyboard))
    
    elif query.data.startswith("confirm_done_"):
        task_id = int(query.data.split("_")[2])
        mark_task_done(task_id)
        text = format_task_list(user_id)
        await query.edit_message_text(text, reply_markup=get_main_keyboard())
    
    elif query.data == "refresh":
        text = format_task_list(user_id)
        await query.edit_message_text(text, reply_markup=get_main_keyboard())
    
    elif query.data == "back_to_main":
        text = format_task_list(user_id)
        await query.edit_message_text(text, reply_markup=get_main_keyboard())

async def message_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    
    if context.user_data.get('waiting_for_password'):
        await password_handler(update, context)
        return
    
    if context.user_data.get('waiting_for_task_text'):
        context.user_data['task_text'] = update.message.text
        context.user_data['waiting_for_task_text'] = False
        context.user_data['waiting_for_category'] = True
        
        keyboard = [[InlineKeyboardButton(cat, callback_data=f"category_{cat}")] for cat in CATEGORIES]
        await update.message.reply_text("کار را به کدام دسته اضافه کنی؟", reply_markup=InlineKeyboardMarkup(keyboard))
        return

@app.route("/")
def index():
    return "✅ TodoBot is running on Railway!"

@app.route("/webhook", methods=["POST"])
async def webhook():
    try:
        json_data = request.get_json(force=True)
        update = Update.de_json(json_data, application.bot)
        await application.process_update(update)
        return "OK", 200
    except Exception as e:
        logger.error(f"Webhook error: {e}")
        return "Error", 500

async def initialize_bot():
    await application.initialize()
    await application.start()
    logger.info("🚀 Application initialized successfully!")

if __name__ == "__main__":
    init_db()
    port = int(os.environ.get("PORT", 8080))
    
    # Initialize the Application before starting Flask
    asyncio.run(initialize_bot())
    
    logger.info(f"🚀 Starting bot on port {port}")
    app.run(host="0.0.0.0", port=port)