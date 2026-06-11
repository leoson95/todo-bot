import os
import logging
from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.ext import Application, CommandHandler, MessageHandler, CallbackQueryHandler, filters, ContextTypes
from config import BOT_PASSWORD
from database import init_db, add_task, get_user_tasks, mark_task_done

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Categories
CATEGORIES = [
    "کارای سالن",
    "خریدای خونه",
    "کارای خونه",
    "خریدای سالن",
    "کارای من"
]

async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    
    # Simple password check (we will improve this)
    if context.user_data.get('authenticated'):
        await show_main_menu(update, context)
        return
    
    await update.message.reply_text(
        "🔐 برای استفاده از ربات، لطفاً رمز عبور را وارد کن:",
        reply_markup=InlineKeyboardMarkup([[
            InlineKeyboardButton("🔑 ورود رمز", callback_data="enter_password")
        ]])
    )

async def show_main_menu(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    tasks = get_user_tasks(user_id)
    
    text = "📝 لیست کارهای شما\n\n"
    
    for category in CATEGORIES:
        text += f"🗂 {category}\n"
        category_tasks = [t for t in tasks if t['category'] == category]
        if category_tasks:
            for task in category_tasks:
                text += f"🔲 {task['text']}\n"
        else:
            text += "🔲 ( خالی )\n"
        text += "\n"
    
    keyboard = [
        [InlineKeyboardButton("➕ افزودن کار جدید", callback_data="add_task")],
        [InlineKeyboardButton("✅ علامت زدن انجام‌شده", callback_data="mark_done")],
        [InlineKeyboardButton("🔄 به‌روزرسانی لیست", callback_data="refresh")]
    ]
    
    reply_markup = InlineKeyboardMarkup(keyboard)
    
    if update.callback_query:
        await update.callback_query.edit_message_text(text, reply_markup=reply_markup)
    else:
        await update.message.reply_text(text, reply_markup=reply_markup)

async def button_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()
    
    if query.data == "add_task":
        await query.edit_message_text("لطفاً متن کار را بنویس:")
        context.user_data['waiting_for_task'] = True
    elif query.data == "refresh":
        await show_main_menu(update, context)
    # We will expand other buttons later

async def message_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if context.user_data.get('waiting_for_task'):
        # Simple add task for now
        user_id = update.effective_user.id
        text = update.message.text
        # For now, add to first category
        add_task(user_id, CATEGORIES[0], text)
        context.user_data['waiting_for_task'] = False
        await update.message.reply_text("✅ کار اضافه شد.")
        await show_main_menu(update, context)

async def main():
    init_db()
    
    application = Application.builder().token(os.getenv("TELEGRAM_BOT_TOKEN")).build()
    
    application.add_handler(CommandHandler("start", start))
    application.add_handler(CallbackQueryHandler(button_handler))
    application.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, message_handler))
    
    logger.info("Bot started...")
    await application.run_polling()

if __name__ == "__main__":
    import asyncio
    asyncio.run(main())