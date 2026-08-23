<?php 
session_start();
// If already logged in, send to dashboard instead of looping
if(isset($_SESSION['user_id'])){
  header("Location: week.php"); 
  exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ShiftPro - Система планирования смен и учета рабочего времени</title>
<meta name="description" content="Создавайте графики смен за 5 минут. Контроль выходов, замена сотрудников, авто-напоминания в SMS. 14 дней бесплатно.">
<style>
  :root{--blue:#2563eb;--blue-dark:#1d4ed8;--dark:#0f172a;--gray:#64748b;--light:#f8fafc;--border:#e2e8f0;--green:#10b981}
  *{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto}
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;800&display=swap');
  body{background:var(--light);color:var(--dark);line-height:1.6;-webkit-font-smoothing:antialiased}
  .container{max-width:1200px;margin:0 auto;padding:0 24px}
  
  header{padding:18px 0;background:rgba(255,255,255,0.8);backdrop-filter:blur(10px);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
  nav{display:flex;justify-content:space-between;align-items:center}
  .logo{font-weight:800;font-size:24px;color:var(--blue);text-decoration:none;letter-spacing:-0.5px}
  .nav-links{display:flex;gap:32px;align-items:center}
  .nav-links a{color:var(--dark);text-decoration:none;font-weight:500;transition:0.2s}
  .nav-links a:hover{color:var(--blue)}
  .btn{background:var(--blue);color:white;padding:12px 22px;border-radius:10px;text-decoration:none;font-weight:600;border:none;cursor:pointer;transition:all 0.2s;display:inline-block;text-align:center;box-shadow:0 1px 3px rgba(37,99,235,0.2)}
  .btn:hover{background:var(--blue-dark);transform:translateY(-2px) ;box-shadow:0 4px 12px rgba(37,99,235,0.3);color:white} /* FIXED */
  .btn-outline{background:transparent;color:var(--blue);border:1px solid var(--blue);box-shadow:none}
  .btn-outline:hover{background:var(--blue);color:white !important} /* FIXED */
  .btn-lg{padding:16px 32px;font-size:17px}
  .btn-white{background:white;color:var(--dark);border:1px solid var(--border)}
  .btn-white:hover{background:var(--light);color:var(--dark) !important} /* FIXED */
  
  .hero{padding:120px 0;background:linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);position:relative;overflow:hidden}
  .hero::before{content:'';position:absolute;top:-200px;right:-200px;width:600px;height:600px;background:radial-gradient(circle, rgba(37,99,235,0.08) 0%, transparent 70%)}
  .hero-wrap{display:grid;grid-template-columns:1fr 1fr;gap:80px;align-items:center;position:relative;z-index:2}
  .hero h1{font-size:52px;line-height:1.1;margin-bottom:24px;font-weight:800;letter-spacing:-1.5px}
  .hero p{font-size:19px;color:var(--gray);margin-bottom:32px;max-width:520px}
  .badge{display:inline-flex;gap:8px;align-items:center;background:#eff6ff;color:var(--blue);padding:10px 16px;border-radius:30px;font-size:14px;font-weight:600;margin-bottom:24px;border:1px solid #dbeafe}
  .hero-img{background:white;border:1px solid var(--border);border-radius:20px;padding:24px;box-shadow:0 20px 50px rgba(0,0,0,0.08)}
  
  .section{padding:100px 0}
  .section-title{text-align:center;font-size:42px;margin-bottom:16px;font-weight:800;letter-spacing:-1px}
  .section-sub{text-align:center;color:var(--gray);max-width:650px;margin:0 auto 60px;font-size:18px}
  
  .grid-3{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:32px}
  .feature-card{background:white;padding:40px;border-radius:20px;border:1px solid var(--border);transition:all 0.3s}
  .feature-card:hover{transform:translateY(-4px);box-shadow:0 12px 30px rgba(0,0,0,0.06);border-color:var(--blue)}
  .feature-card .icon{width:56px;height:56px;background:linear-gradient(135deg,#eff6ff 0%,#dbeafe 100%);border-radius:14px;display:grid;place-items:center;font-size:28px;margin-bottom:24px}
  .feature-card h3{font-size:20px;margin-bottom:10px}
  
  .pricing-grid{display:grid;grid-template-columns:repeat(3, 1fr);gap:32px;max-width:1100px;margin:0 auto}
  .price-card{background:white;border:1px solid var(--border);padding:40px;border-radius:20px;position:relative;display:flex;flex-direction:column;transition:all 0.3s;min-height:480px}
  .price-card:hover{transform:translateY(-4px);box-shadow:0 12px 30px rgba(0,0,0,0.06)}
  .price-card.featured{border:2px solid var(--blue);box-shadow:0 20px 50px rgba(37,99,235,0.15);transform:scale(1.03)}
  .tag{position:absolute;top:-14px;left:50%;transform:translateX(-50%);background:var(--blue);color:white;padding:6px 16px;border-radius:20px;font-size:12px;font-weight:700;letter-spacing:0.5px}
  .price{font-size:48px;font-weight:800;margin:20px 0}
  .price span{font-size:16px;color:var(--gray);font-weight:400}
  .price-card ul{list-style:none;margin:28px 0;flex-grow:1}
  .price-card ul li{padding:10px 0;display:flex;gap:12px;font-weight:500}
  .price-card ul li:before{content:"✓";color:var(--green);font-weight:800;font-size:18px}
  
  .cta{background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);color:white;padding:100px 0;text-align:center;position:relative;overflow:hidden}
  .cta::before{content:'';position:absolute;bottom:-100px;left:-100px;width:400px;height:400px;background:radial-gradient(circle, rgba(37,99,235,0.15) 0%, transparent 70%)}
  .cta h2{color:white}
  .cta p{color:#cbd5e1;font-size:18px}
  .cta form{max-width:500px;margin:32px auto 0;display:flex;gap:12px}
  .cta input{flex:1;padding:18px;border-radius:12px;border:1px solid #334155;background:#1e293b;color:white;font-size:16px}
  .cta input::placeholder{color:#64748b}
  
  footer{padding:60px 0;text-align:center;color:var(--gray);background:white;border-top:1px solid var(--border)}
  @media(max-width:980px){
    .hero-wrap{grid-template-columns:1fr}
    .hero h1{font-size:38px}
    .nav-links{display:none}
    .price-card.featured{transform:scale(1)}
    .pricing-grid{grid-template-columns:1fr}
  }
</style>
</head>
<body>

<header>
  <div class="container">
    <nav>
      <a href="landing.php" class="logo">ShiftPro</a>
      <div class="nav-links">
        <a href="#features">Возможности</a>
        <a href="#pricing">Тарифы</a>
        <a href="index.php" class="btn-outline btn">Войти</a>
        <a href="register.php?plan=2" class="btn">14 дней бесплатно</a>
      </div>
    </nav>
  </div>
</header>

<section class="hero">
  <div class="container hero-wrap">
    <div>
      <div class="badge">14 дней бесплатно • Без карты • Настройка 5 минут</div>
      <h1>Создавайте идеальные графики за минуты, а не часы</h1>
      <p>Единая система планирования смен и учета рабочего времени для кафе, клиник, магазинов и охранных предприятий. Создавайте смены, меняйте сотрудников, исключайте опоздания с помощью авто-напоминаний.</p>
      <div style="display:flex;gap:16px;flex-wrap:wrap">
        <a href="register.php?plan=2" class="btn btn-lg">Начать бесплатно</a>
        <a href="#features" class="btn-outline btn btn-white">Как это работает</a>
      </div>
    </div>
    <div class="hero-img">
      <img src="https://placehold.co/600x400/eff6ff/2563eb?text=График+смен+на+месяц" alt="Dashboard" style="width:100%;border-radius:12px">
    </div>
  </div>
</section>

<section class="section" id="features">
  <div class="container">
    <h2 class="section-title">Всё для управления вашей командой</h2>
    <p class="section-sub">Забудьте про Excel и бумажки. Дайте руководителям и сотрудникам один мощный инструмент.</p>
    <div class="grid-3">
      <div class="feature-card"><div class="icon">📅</div><h3>Конструктор графиков Drag & Drop</h3><p>Создавайте расписание на месяц за минуты. Копируйте смены, используйте шаблоны. Автоматический учет переработок и ночных часов.</p></div>
      <div class="feature-card"><div class="icon">🔄</div><h3>Замена сотрудника в 1 клик</h3><p>Кто-то заболел? Найдите замену и уведомите всю команду через SMS, WhatsApp или Telegram за 10 секунд.</p></div>
      <div class="feature-card"><div class="icon">📱</div><h3>Автоматические напоминания</h3><p>Сократите опоздания на 80%. Сотрудники получают SMS за 12ч, 6ч и 3ч до начала смены.</p></div>
      <div class="feature-card"><div class="icon">📊</div><h3>Отчеты и аналитика</h3><p>Отслеживайте отработанные часы и ФОТ. Выгружайте отчеты в Excel для бухгалтерии в 1 клик.</p></div>
      <div class="feature-card"><div class="icon">👥</div><h3>Личный кабинет сотрудника</h3><p>Сотрудники видят свой график, могут запросить выходной и поменяться сменами прямо с телефона.</p></div>
      <div class="feature-card"><div class="icon">⚡</div><h3>Готовые интеграции</h3><p>Работает с SMSC, Telegram, Google Calendar и 1С. Доступ с компьютера и телефона 24/7.</p></div>
    </div>
  </div>
</section>

<section class="section" id="pricing" style="background:white">
  <div class="container">
    <h2 class="section-title">Простые и прозрачные тарифы</h2>
    <p class="section-sub">Выберите план и оплатите помесячно. Или начните с 14-дневного бесплатного периода выше.</p>
    <div class="pricing-grid">
      <div class="price-card">
        <h3>Базовый</h3>
        <div class="price">990<span>₽/мес</span></div>
        <ul><li>До 10 сотрудников</li><li>200 SMS в подарок</li><li>Графики + Отчеты</li><li>Email поддержка</li></ul>
        <a href="billing.php?plan=1" class="btn btn-outline" style="width:100%">Выбрать Базовый</a>
      </div>
      <div class="price-card featured">
        <div class="tag">ХИТ ПРОДАЖ</div>
        <h3>Профи</h3>
        <div class="price">2490<span>₽/мес</span></div>
        <ul><li>До 50 сотрудников</li><li>1000 SMS в подарок</li><li>SMS + WhatsApp + Telegram</li><li>API и Интеграции</li><li>Приоритетная поддержка</li></ul>
        <a href="billing.php?plan=2" class="btn" style="width:100%">Выбрать Профи</a>
      </div>
      <div class="price-card">
        <h3>Бизнес</h3>
        <div class="price">4990<span>₽/мес</span></div>
        <ul><li>Без лимита сотрудников</li><li>5000 SMS в подарок</li><li>Выделенный менеджер</li><li>Кастомные отчеты</li></ul>
        <a href="billing.php?plan=3" class="btn btn-outline" style="width:100%">Выбрать Бизнес</a>
      </div>
    </div>
  </div>
</section>

<section class="cta" id="cta">
  <div class="container">
    <h2 class="section-title" style="color:white">Готовы экономить 10 часов каждую неделю?</h2>
    <p>Присоединяйтесь к 200+ компаниям, которые уже доверяют ShiftPro управление командой.</p>
    <form action="register.php" method="get">
      <input type="email" name="email" placeholder="Введите ваш рабочий email" required>
      <input type="hidden" name="plan" value="2">
      <button class="btn" style="background:white;color:var(--dark)">Начать бесплатно</button>
    </form>
    <p style="font-size:14px;margin-top:16px;opacity:0.7">Уже есть аккаунт? <a href="index.php" style="color:white;text-decoration:underline">Войти</a></p>
  </div>
</section>

<footer>
  <div class="container">
    © 2026 ShiftPro. Система планирования смен №1 для растущего бизнеса в РФ. <br>
    support@shiftpro.com
  </div>
</footer>

</body>
</html>