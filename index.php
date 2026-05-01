<?php
declare(strict_types=1);

require_once __DIR__ . '/api/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
<title>Aurora State College of Technology — Student Information Management System</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Barlow+Condensed:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ============================================ */
/* CSS VARIABLES & RESET                        */
/* ============================================ */
:root {
    --black: #000000;
    --dark: #0b0d0e;
    --dark-gray: #111314;
    --mid-gray: #242628;
    --light-gray: #383c40;
    --silver: #c8c9cc;
    --light-silver: #e0e0e0;
    --orange: #ff5a1f;
    --orange-dark: #cc4a18;
    --orange-glow: rgba(255,90,31,0.25);
    --white: #ffffff;
    --off-white: #e8e8e8;
    --muted: #8a8d92;
    --inactive-bg: #2a2a2a;
    --inactive-text: #777;
    --success: #28a745;
    --error: #dc3545;
    --warning: #e8a317;
    --sidebar-w: 284px;
    --header-h: 80px;
    --font-heading: 'Bebas Neue', sans-serif;
    --font-body: 'Barlow Condensed', sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:16px;scroll-behavior:smooth}
body{font-family:var(--font-body);background:radial-gradient(circle at 55% -20%,rgba(255,255,255,0.035),transparent 42%),var(--dark);color:var(--off-white);overflow-x:hidden;min-height:100vh;line-height:1.5}
a{color:inherit;text-decoration:none}
button{cursor:pointer;font-family:var(--font-body);border:none;outline:none}
input,select,textarea{font-family:var(--font-body);outline:none}
:focus-visible{outline:2px solid var(--orange);outline-offset:2px}
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:var(--dark-gray)}
::-webkit-scrollbar-thumb{background:var(--light-gray);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:var(--silver)}

/* ============================================ */
/* ANIMATIONS                                   */
/* ============================================ */
@keyframes fadeIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeInUp{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:translateY(0)}}
@keyframes slideInLeft{from{transform:translateX(-100%)}to{transform:translateX(0)}}
@keyframes slideInRight{from{opacity:0;transform:translateX(40px)}to{opacity:1;transform:translateX(0)}}
@keyframes pulseGlow{0%,100%{box-shadow:0 0 8px var(--orange-glow)}50%{box-shadow:0 0 24px var(--orange-glow)}}
@keyframes shimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}
@keyframes toastIn{from{opacity:0;transform:translateX(100%)}to{opacity:1;transform:translateX(0)}}
@keyframes toastOut{from{opacity:1;transform:translateX(0)}to{opacity:0;transform:translateX(100%)}}
@keyframes modalIn{from{opacity:0;transform:scale(0.92)}to{opacity:1;transform:scale(1)}}
@keyframes lineGlow{0%,100%{opacity:0.4}50%{opacity:1}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:0.01ms!important;transition-duration:0.01ms!important}}

/* ============================================ */
/* UTILITY CLASSES                              */
/* ============================================ */
.fade-in{animation:fadeIn 0.4s ease both}
.fade-in-up{animation:fadeInUp 0.5s ease both}
.stagger-1{animation-delay:0.05s}.stagger-2{animation-delay:0.1s}.stagger-3{animation-delay:0.15s}
.stagger-4{animation-delay:0.2s}.stagger-5{animation-delay:0.25s}.stagger-6{animation-delay:0.3s}
.hidden{display:none!important}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);border:0}

/* ============================================ */
/* TOAST NOTIFICATIONS                          */
/* ============================================ */
#toast-container{position:fixed;top:80px;right:20px;z-index:10000;display:flex;flex-direction:column;gap:10px;pointer-events:none}
.toast{pointer-events:auto;min-width:300px;max-width:420px;padding:14px 20px;border-radius:6px;display:flex;align-items:center;gap:12px;font-size:0.95rem;font-weight:500;animation:toastIn 0.35s ease both;backdrop-filter:blur(10px);border:1px solid var(--light-gray)}
.toast.removing{animation:toastOut 0.3s ease both}
.toast-success{background:rgba(40,167,69,0.15);border-color:rgba(40,167,69,0.4);color:#5ddb85}
.toast-error{background:rgba(220,53,69,0.15);border-color:rgba(220,53,69,0.4);color:#f07080}
.toast-warning{background:rgba(232,163,23,0.15);border-color:rgba(232,163,23,0.4);color:#f0c050}
.toast-info{background:rgba(192,192,192,0.1);border-color:rgba(192,192,192,0.3);color:var(--silver)}
.toast i{font-size:1.1rem;flex-shrink:0}
.toast-msg{flex:1}
.toast-close{background:none;color:inherit;opacity:0.6;font-size:0.85rem;padding:4px}
.toast-close:hover{opacity:1}

/* ============================================ */
/* MODAL OVERLAY                                */
/* ============================================ */
.modal-overlay{position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,0.75);display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity 0.25s ease;backdrop-filter:blur(4px)}
#confirm-modal{z-index:9100}
.modal-overlay.active{opacity:1;pointer-events:auto}
.modal-overlay.active .modal-box{animation:modalIn 0.3s ease both}
.modal-box{background:var(--dark-gray);border:1px solid var(--light-gray);border-radius:8px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;position:relative}
.modal-header{padding:20px 24px;border-bottom:1px solid var(--mid-gray);display:flex;align-items:center;justify-content:space-between}
.modal-header h2{font-family:var(--font-heading);font-size:1.7rem;letter-spacing:2px;color:var(--white)}
.modal-close{background:none;color:var(--silver);font-size:1.3rem;padding:4px 8px;border-radius:4px;transition:all 0.2s}
.modal-close:hover{color:var(--orange);background:var(--mid-gray)}
.modal-body{padding:24px}
.modal-footer{padding:16px 24px;border-top:1px solid var(--mid-gray);display:flex;justify-content:flex-end;gap:10px}

/* ============================================ */
/* LOGIN PAGE                                   */
/* ============================================ */
#login-page{min-height:100vh;display:flex;position:relative;overflow:hidden}
.login-hero{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px;position:relative;overflow:hidden;background:linear-gradient(135deg,#080808 0%,#171717 52%,#080808 100%)}
.login-hero::before{content:'';position:absolute;inset:-1%;background:url('assets/img/asct-login-bg.png') center/cover;opacity:0.38;filter:saturate(0.78) contrast(1.05)}
.login-hero::after{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(5,5,5,0.96),rgba(8,8,8,0.9) 52%,rgba(5,5,5,0.97))}
.login-hero-content{position:relative;z-index:2;max-width:560px;text-align:center}
.brand-logo{display:block;object-fit:contain}
.login-brand{width:min(420px,82vw);height:auto;margin:0 auto 12px}
.login-tagline{font-family:var(--font-heading);font-size:1.5rem;letter-spacing:6px;color:var(--silver);margin-bottom:32px;opacity:0.8}
.login-form-side{width:480px;min-width:400px;display:flex;flex-direction:column;justify-content:center;padding:60px;background:var(--dark-gray);border-left:1px solid var(--mid-gray);position:relative}
.login-form-side::before{content:'';position:absolute;top:0;left:0;width:3px;height:100%;background:linear-gradient(180deg,var(--orange),transparent);animation:lineGlow 3s ease infinite}
.login-form-title{font-family:var(--font-heading);font-size:2.2rem;letter-spacing:4px;margin-bottom:8px;color:var(--white)}
.login-form-sub{color:var(--muted);margin-bottom:32px;font-size:0.95rem}
.form-group{margin-bottom:20px}
.form-group label{display:block;font-weight:600;font-size:0.9rem;letter-spacing:1px;text-transform:uppercase;color:var(--silver);margin-bottom:6px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:12px 14px;background:var(--mid-gray);border:1px solid var(--light-gray);border-radius:5px;color:var(--white);font-size:1rem;transition:all 0.2s}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--orange);box-shadow:0 0 0 3px var(--orange-glow)}
.form-group input::placeholder{color:var(--muted)}
.form-group input:disabled,.form-group select:disabled,.form-group textarea:disabled{opacity:0.5;cursor:not-allowed;background:var(--dark-gray)}
.form-group .error-msg{color:var(--error);font-size:0.82rem;margin-top:4px;display:none}
.form-group.has-error input,.form-group.has-error select{border-color:var(--error)}
.form-group.has-error .error-msg{display:block}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.login-error{background:rgba(220,53,69,0.1);border:1px solid rgba(220,53,69,0.3);border-radius:5px;padding:10px 14px;color:#f07080;font-size:0.9rem;margin-bottom:16px;display:none;align-items:center;gap:8px}
.login-error.show{display:flex}
.login-error.success{background:rgba(40,167,69,0.12);border-color:rgba(40,167,69,0.35);color:#5ddb85}
.login-note{background:rgba(255,122,26,0.08);border:1px solid rgba(255,122,26,0.25);border-radius:5px;padding:12px 14px;color:var(--silver);font-size:0.92rem;line-height:1.5;margin-bottom:18px}
.login-note strong{color:var(--white);font-weight:700}
.login-inline-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:16px}
.login-link-btn{background:none;color:var(--silver);font-weight:700;font-size:0.85rem;text-transform:uppercase;letter-spacing:1px;padding:6px 0}
.login-link-btn:hover{color:var(--orange)}
.login-link-btn:disabled{opacity:0.5;cursor:not-allowed;color:var(--muted)}
.login-divider{display:flex;align-items:center;gap:12px;margin:18px 0;color:var(--muted);font-size:0.75rem;font-weight:700;letter-spacing:2px;text-transform:uppercase}
.login-divider::before,.login-divider::after{content:'';height:1px;background:var(--light-gray);flex:1}
.passkey-actions{display:flex;align-items:flex-end;gap:12px;margin-top:18px}
.passkey-actions .form-group{flex:1;margin-bottom:0}
.passkey-list{display:flex;flex-direction:column;gap:10px;margin-top:16px}
.passkey-row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 0;border-bottom:1px solid var(--mid-gray)}
.passkey-row:last-child{border-bottom:0}
.passkey-name{font-weight:700;color:var(--white);letter-spacing:0.5px}
.passkey-meta{color:var(--muted);font-size:0.85rem;margin-top:3px}

/* ============================================ */
/* BUTTONS                                      */
/* ============================================ */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 22px;border-radius:5px;font-weight:700;font-size:0.95rem;letter-spacing:0.8px;transition:all 0.2s;text-transform:uppercase}
.btn-primary{background:var(--orange);color:var(--white)}
.btn-primary:hover{background:var(--orange-dark);box-shadow:0 0 20px var(--orange-glow)}
.btn-secondary{background:var(--mid-gray);color:var(--silver);border:1px solid var(--light-gray)}
.btn-secondary:hover{background:var(--light-gray);color:var(--white)}
.btn-export{background:rgba(33,115,70,0.18);color:#8de0a9;border:1px solid rgba(33,115,70,0.56)}
.btn-export:hover{background:#217346;color:var(--white);border-color:#2f9d61;box-shadow:0 0 20px rgba(33,115,70,0.24)}
.btn-danger{background:rgba(220,53,69,0.15);color:#f07080;border:1px solid rgba(220,53,69,0.3)}
.btn-danger:hover{background:rgba(220,53,69,0.3)}
.btn-ghost{background:transparent;color:var(--silver);border:1px solid var(--light-gray)}
.btn-ghost:hover{border-color:var(--orange);color:var(--orange)}
.btn-sm{padding:6px 14px;font-size:0.82rem}
.btn-lg{padding:14px 32px;font-size:1.05rem;letter-spacing:1px}
.btn-block{width:100%}
.btn-icon{padding:8px 10px;font-size:0.9rem}

/* ============================================ */
/* APP LAYOUT                                   */
/* ============================================ */
#app-container{display:none;min-height:100vh}
#app-header{position:fixed;top:0;left:0;right:0;height:var(--header-h);background:rgba(17,19,20,0.98);border-bottom:1px solid #2a2d30;display:flex;align-items:center;justify-content:space-between;padding:0 38px;z-index:100}
#app-header::after{display:none}
.header-left{display:flex;align-items:center;gap:0}
.header-brand{width:108px;height:auto;max-height:34px;flex-shrink:0}
.header-divider,.header-page-title{display:none}
.header-right{display:flex;align-items:center;gap:28px}
.header-user{display:flex;align-items:center;gap:13px}
.header-avatar{width:40px;height:40px;border-radius:50%;background:transparent;border:2px solid var(--orange);display:flex;align-items:center;justify-content:center;font-family:var(--font-heading);font-size:1rem;letter-spacing:1px;color:var(--orange);overflow:hidden;position:relative}
.avatar-img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}
.avatar-img[hidden]{display:none}
.avatar-fallback{position:relative;z-index:1;display:flex;align-items:center;justify-content:center;width:100%;height:100%}
.header-user-info{line-height:1.2}
.header-user-name{font-weight:600;font-size:0.95rem;color:var(--white)}
.role-badge{display:inline-block;padding:3px 9px;border-radius:4px;font-size:0.7rem;font-weight:800;letter-spacing:1.2px;line-height:1.05;text-transform:uppercase}
.role-badge.admin{background:rgba(255,90,31,0.12);color:var(--orange);border:1px solid rgba(255,90,31,0.75)}
.role-badge.teacher{background:rgba(255,255,255,0.055);color:#c9c9c9;border:1px solid rgba(255,255,255,0.18)}
.role-badge.student{background:rgba(25,118,210,0.16);color:#55a7ff;border:1px solid rgba(25,118,210,0.45)}
.account-status-badge{display:inline-block;padding:5px 12px;border-radius:4px;font-size:0.74rem;font-weight:800;letter-spacing:1.3px;line-height:1;text-transform:uppercase}
.account-status-badge.active{background:rgba(40,167,69,0.16);color:#5ddb85;border:1px solid rgba(40,167,69,0.48)}
.account-status-badge.pending{background:rgba(232,163,23,0.15);color:#f0c050;border:1px solid rgba(232,163,23,0.4)}
.btn-logout{background:none;color:#8f9296;display:flex;align-items:center;gap:8px;font-size:0.88rem;padding:6px 0;border-radius:4px;transition:all 0.2s}
.btn-logout:hover{color:var(--error);background:rgba(220,53,69,0.1)}
.hamburger{display:none;background:none;color:var(--silver);font-size:1.3rem;padding:6px}

/* ============================================ */
/* SIDEBAR                                      */
/* ============================================ */
#sidebar{position:fixed;top:var(--header-h);left:0;bottom:0;width:var(--sidebar-w);background:rgba(17,19,20,0.98);border-right:1px solid #2a2d30;padding:32px 0;z-index:90;overflow-y:auto;transition:transform 0.3s ease}
.sidebar-section{padding:0 23px;margin-bottom:20px}
.sidebar-section-title{font-family:var(--font-heading);font-size:0.78rem;letter-spacing:3px;color:#8e9297;margin-bottom:25px;padding-left:15px;text-transform:uppercase}
.sidebar-nav{list-style:none}
.sidebar-nav li{margin-bottom:11px}
.sidebar-nav a{display:flex;align-items:center;gap:16px;min-height:55px;padding:14px 17px;border-radius:4px;color:#d0d1d4;font-weight:500;font-size:1rem;transition:all 0.2s;letter-spacing:0.15px;border-left:3px solid transparent}
.sidebar-nav a i{width:20px;text-align:center;font-size:1.05rem}
.sidebar-nav a:hover{background:var(--mid-gray);color:var(--white)}
.sidebar-nav a.active{background:linear-gradient(90deg,rgba(255,90,31,0.16),rgba(255,255,255,0.045));color:var(--orange);border-left-color:var(--orange)}
.sidebar-nav a.active i{color:var(--orange)}
.sidebar-footer{position:absolute;bottom:0;left:0;right:0;padding:29px 38px;border-top:1px solid #2a2d30}
.sidebar-footer-text{font-size:0.82rem;color:#8e9297;text-align:left;letter-spacing:0.4px}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:85}

/* ============================================ */
/* MAIN CONTENT                                 */
/* ============================================ */
#main-content{margin-left:var(--sidebar-w);margin-top:var(--header-h);min-height:calc(100vh - var(--header-h));padding:0}
.content-section{display:none;padding:54px 61px 72px 54px;animation:fadeIn 0.35s ease both}
.content-section.active{display:block}

/* ============================================ */
/* HERO BANNER                                  */
/* ============================================ */
.hero-banner{position:relative;overflow:visible;margin-bottom:37px;padding:0 0 32px;background:transparent;border:0;border-radius:0}
.hero-banner::before{display:none}
.hero-banner::after{content:'';position:absolute;bottom:0;left:0;width:82px;height:2px;background:var(--orange)}
.hero-banner-content{position:relative;z-index:2}
.hero-banner h1{font-family:var(--font-heading);font-size:2.35rem;letter-spacing:3px;color:var(--white);line-height:1.02;margin-bottom:8px}
.hero-banner h1 span{color:var(--orange)}
.hero-banner p{font-size:1.06rem;color:#aeb0b5;letter-spacing:0.4px;max-width:600px}
.hero-banner .hero-accent{font-family:var(--font-heading);font-size:0.95rem;letter-spacing:3px;color:var(--orange);opacity:0.7;margin-top:12px}

/* ============================================ */
/* STAT CARDS                                   */
/* ============================================ */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:32px}
.stat-card{background:var(--dark-gray);border:1px solid var(--mid-gray);border-radius:8px;padding:24px;position:relative;overflow:hidden;transition:all 0.3s}
.stat-card:hover{border-color:var(--orange);transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,0.3)}
.stat-card::before{content:'';position:absolute;top:0;left:0;width:3px;height:100%;background:var(--orange)}
.stat-card .stat-label{font-size:0.8rem;font-weight:600;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin-bottom:8px}
.stat-card .stat-value{font-family:var(--font-heading);font-size:2.8rem;letter-spacing:2px;color:var(--white);line-height:1}
.stat-card .stat-icon{position:absolute;top:16px;right:16px;font-size:1.5rem;color:var(--light-gray);opacity:0.4}
.stat-card.accent .stat-value{color:var(--orange)}
.stat-card.silver .stat-value{color:var(--silver)}
.stat-card[data-action]{cursor:pointer}
.stat-card[data-action]:focus-visible{border-color:var(--orange);box-shadow:0 0 0 3px var(--orange-glow)}

/* ============================================ */
/* QUICK ACTIONS                                */
/* ============================================ */
.quick-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:32px}
.action-card{background:var(--dark-gray);border:1px solid var(--mid-gray);border-radius:8px;padding:20px;cursor:pointer;transition:all 0.25s;display:flex;align-items:center;gap:14px;text-decoration:none}
.action-card:hover{border-color:var(--orange);background:var(--mid-gray);transform:translateY(-2px)}
.action-card .action-icon{width:44px;height:44px;border-radius:6px;background:rgba(255,90,31,0.12);display:flex;align-items:center;justify-content:center;color:var(--orange);font-size:1.1rem;flex-shrink:0}
.action-card .action-text{font-weight:600;font-size:0.9rem;color:var(--white);letter-spacing:0.5px}
.action-card .action-sub{font-size:0.78rem;color:var(--muted);margin-top:2px}

/* ============================================ */
/* SECTION PANELS                               */
/* ============================================ */
.section-panel{margin-bottom:32px}
.section-panel-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:15px;padding-bottom:0;border-bottom:0}
.section-panel-header h2{font-family:var(--font-heading);font-size:1.45rem;letter-spacing:2.7px;color:var(--white)}
.section-panel-header h2 span{color:var(--white)}
.section-panel-header .section-sub{font-size:0.95rem;color:#9ca0a6;letter-spacing:0.35px;margin-top:3px}

/* Approval queue */
.approval-summary{display:flex;align-items:center;gap:12px;color:var(--silver);font-size:0.9rem}
.approval-count{min-width:34px;height:34px;border-radius:6px;background:rgba(232,163,23,0.14);border:1px solid rgba(232,163,23,0.35);color:#f0c050;display:flex;align-items:center;justify-content:center;font-family:var(--font-heading);font-size:1.3rem;letter-spacing:1px}
.approval-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}
.approval-card{background:var(--dark-gray);border:1px solid var(--mid-gray);border-radius:8px;padding:18px;position:relative;overflow:hidden}
.approval-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--warning),transparent 72%)}
.approval-card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:16px}
.approval-name{font-family:var(--font-heading);font-size:1.35rem;letter-spacing:2px;color:var(--white);line-height:1.1}
.approval-email{color:var(--muted);font-size:0.9rem;margin-top:4px;word-break:break-word}
.approval-meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
.approval-meta{padding:10px 0;border-bottom:1px solid var(--mid-gray)}
.approval-meta label{display:block;color:var(--muted);font-size:0.72rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:3px}
.approval-meta span{color:var(--off-white);font-size:0.95rem;font-weight:600}
.approval-actions{display:flex;gap:8px;flex-wrap:wrap}
.approval-empty{border:1px dashed var(--light-gray);border-radius:8px;padding:24px;color:var(--muted);display:flex;align-items:center;gap:14px}
.approval-empty i{font-size:1.5rem;color:var(--success);opacity:0.8}
.approval-empty strong{display:block;color:var(--silver);font-family:var(--font-heading);font-size:1.2rem;letter-spacing:2px}

/* Programs / Courses showcase */
.programs-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}
.program-card{background:var(--dark-gray);border:1px solid var(--mid-gray);border-radius:8px;overflow:hidden;transition:all 0.3s;position:relative}
.program-card:hover{border-color:var(--orange);transform:translateY(-3px);box-shadow:0 12px 32px rgba(0,0,0,0.4)}
.program-card-img{height:150px;background:linear-gradient(135deg,#151515,var(--mid-gray));position:relative;display:flex;align-items:center;justify-content:center;overflow:hidden}
.program-card-img::before{content:'';position:absolute;inset:0;background:linear-gradient(90deg,rgba(255,90,31,0.16) 0 1px,transparent 1px),linear-gradient(0deg,rgba(255,255,255,0.05) 0 1px,transparent 1px);background-size:34px 34px;opacity:0.38}
.program-card-img::after{content:'';position:absolute;left:22px;right:22px;bottom:0;height:1px;background:linear-gradient(90deg,var(--orange),transparent)}
.program-card-img .program-mark{position:relative;z-index:2;width:74px;height:74px;border-radius:8px;background:rgba(255,90,31,0.12);border:1px solid rgba(255,90,31,0.34);display:flex;align-items:center;justify-content:center;color:var(--orange);font-size:2rem;box-shadow:0 0 24px rgba(255,90,31,0.08)}
.program-card-img .program-code{position:absolute;right:20px;bottom:14px;z-index:2;font-family:var(--font-heading);font-size:1rem;letter-spacing:3px;color:rgba(255,255,255,0.18)}
.program-card-body{padding:20px;position:relative}
.program-card-body::before{content:'';position:absolute;top:0;left:20px;right:20px;height:1px;background:linear-gradient(90deg,var(--orange),transparent)}
.program-card-body h3{font-family:var(--font-heading);font-size:1.4rem;letter-spacing:2px;color:var(--white);margin-bottom:6px}
.program-card-body p{color:var(--muted);font-size:0.88rem;line-height:1.5;margin-bottom:12px}
.program-card-body .program-stat{font-size:0.82rem;color:var(--orange);font-weight:600;letter-spacing:1px}

/* Staff / Teacher profiles */
.staff-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}
.staff-card{background:var(--dark-gray);border:1px solid var(--mid-gray);border-radius:8px;overflow:hidden;transition:all 0.3s;text-align:center}
.staff-card:hover{border-color:var(--silver);transform:translateY(-2px)}
.staff-card-img{height:180px;background:linear-gradient(135deg,#151515,var(--mid-gray));position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center}
.staff-card-img::before{content:'';position:absolute;inset:0;background:linear-gradient(90deg,rgba(255,90,31,0.12) 0 1px,transparent 1px),linear-gradient(0deg,rgba(255,255,255,0.04) 0 1px,transparent 1px);background-size:32px 32px;opacity:0.42}
.staff-card-img::after{content:'FACULTY';position:absolute;right:16px;bottom:12px;font-family:var(--font-heading);font-size:0.95rem;letter-spacing:3px;color:rgba(255,255,255,0.18)}
.staff-avatar-badge{position:relative;z-index:2;width:84px;height:84px;border-radius:50%;background:rgba(255,90,31,0.1);border:2px solid var(--orange);display:flex;align-items:center;justify-content:center;font-family:var(--font-heading);font-size:2rem;letter-spacing:2px;color:var(--orange)}
.staff-card-body{padding:16px}
.staff-card-body h4{font-family:var(--font-heading);font-size:1.2rem;letter-spacing:2px;color:var(--white);margin-bottom:4px}
.staff-card-body .staff-role{color:var(--orange);font-size:0.82rem;font-weight:600;letter-spacing:1px;text-transform:uppercase}
.staff-card-body .staff-detail{color:var(--muted);font-size:0.82rem;margin-top:6px}

/* Member stories */
.stories-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}
.story-card{background:var(--dark-gray);border:1px solid var(--mid-gray);border-radius:8px;overflow:hidden;display:flex;transition:all 0.3s}
.story-card:hover{border-color:var(--orange);transform:translateY(-2px)}
.story-card-img{width:120px;min-height:160px;background:linear-gradient(135deg,#151515,var(--mid-gray));flex-shrink:0;overflow:hidden;position:relative;display:flex;align-items:center;justify-content:center}
.story-card-img::before{content:'';position:absolute;inset:0;background:linear-gradient(90deg,rgba(255,90,31,0.14) 0 1px,transparent 1px),linear-gradient(0deg,rgba(255,255,255,0.04) 0 1px,transparent 1px);background-size:28px 28px;opacity:0.4}
.story-avatar-badge{position:relative;z-index:2;width:60px;height:60px;border-radius:8px;background:rgba(255,90,31,0.12);border:1px solid rgba(255,90,31,0.36);display:flex;align-items:center;justify-content:center;font-family:var(--font-heading);font-size:1.45rem;letter-spacing:2px;color:var(--orange)}
.story-card-body{padding:16px;flex:1;display:flex;flex-direction:column;justify-content:center}
.story-card-body h4{font-family:var(--font-heading);font-size:1.15rem;letter-spacing:2px;color:var(--white);margin-bottom:4px}
.story-card-body .story-course{color:var(--orange);font-size:0.8rem;font-weight:600;letter-spacing:1px;margin-bottom:8px}
.story-card-body p{color:var(--muted);font-size:0.85rem;line-height:1.5;font-style:italic}
.story-card-body .story-highlight{color:var(--silver);font-weight:500;font-style:normal}

/* CTA Banner */
.cta-banner{background:linear-gradient(135deg,rgba(255,90,31,0.1),rgba(255,90,31,0.03));border:1px solid rgba(255,90,31,0.3);border-radius:8px;padding:40px;text-align:center;position:relative;overflow:hidden}
.cta-banner::before{content:'';position:absolute;inset:0;background:linear-gradient(90deg,rgba(255,90,31,0.12) 0 1px,transparent 1px),linear-gradient(0deg,rgba(255,255,255,0.04) 0 1px,transparent 1px),linear-gradient(135deg,transparent 0 64%,rgba(255,90,31,0.12) 64% 66%,transparent 66%);background-size:42px 42px,42px 42px,100% 100%;opacity:0.34}
.cta-banner h2{font-family:var(--font-heading);font-size:2.2rem;letter-spacing:4px;color:var(--white);margin-bottom:8px;position:relative}
.cta-banner p{color:var(--silver);margin-bottom:24px;font-size:1rem;position:relative}
.cta-banner .btn{position:relative}

/* ============================================ */
/* DASHBOARD REDESIGN                           */
/* ============================================ */
#view-dashboard.content-section{padding:24px 24px 64px}
.dashboard-view{width:100%;max-width:none}
.dashboard-view .hero-banner{margin-bottom:20px;padding:30px 26px 24px;background:linear-gradient(135deg,rgba(255,255,255,0.055),rgba(255,255,255,0.018));border:1px solid #303438;border-radius:6px;box-shadow:inset 0 1px 0 rgba(255,255,255,0.035)}
.dashboard-view .hero-banner::before{display:none}
.dashboard-view .hero-banner::after{display:none}
.dashboard-view .hero-banner h1{font-size:2.05rem;letter-spacing:2.5px;line-height:1.05;margin-bottom:16px}
.dashboard-view .hero-banner p{max-width:470px;font-size:0.97rem;line-height:1.5;color:#b9bcc0;letter-spacing:0.1px;margin-bottom:18px}
.dashboard-view .hero-banner .hero-accent{margin-top:0;font-family:var(--font-heading);font-size:0.93rem;font-weight:700;letter-spacing:1.2px;color:var(--orange);opacity:1}
.dashboard-view .stats-grid{grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:20px}
.dashboard-view.dashboard-compact .stats-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
.dashboard-view .stat-card{min-height:100px;padding:22px 20px;background:linear-gradient(135deg,rgba(255,255,255,0.05),rgba(255,255,255,0.018));border:1px solid #303438;border-radius:6px;box-shadow:inset 0 1px 0 rgba(255,255,255,0.03)}
.dashboard-view .stat-card::before{display:none}
.dashboard-view .stat-card:hover{border-color:#3e4348;background:linear-gradient(135deg,rgba(255,255,255,0.065),rgba(255,255,255,0.025));transform:none;box-shadow:inset 0 1px 0 rgba(255,255,255,0.035)}
.dashboard-view .stat-card .stat-label{font-size:0.73rem;font-weight:600;letter-spacing:0.7px;color:#a5a8ad;margin-bottom:8px}
.dashboard-view .stat-card .stat-value{font-size:2rem;letter-spacing:1px;line-height:1;color:var(--white)}
.dashboard-view .stat-card .stat-icon{top:22px;right:20px;font-size:1.05rem;color:#c3c5c8;opacity:0.72}
.dashboard-view .quick-actions{grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:25px}
.dashboard-view.dashboard-compact .quick-actions{grid-template-columns:repeat(4,minmax(0,1fr))}
.dashboard-view .action-card{min-height:68px;padding:15px 18px;background:linear-gradient(135deg,rgba(255,255,255,0.05),rgba(255,255,255,0.018));border:1px solid #303438;border-radius:6px;gap:14px}
.dashboard-view .action-card:hover{border-color:rgba(255,90,31,0.55);background:linear-gradient(135deg,rgba(255,90,31,0.08),rgba(255,255,255,0.025));transform:none}
.dashboard-view .action-card .action-icon{width:28px;height:28px;border-radius:0;background:transparent;border:0;color:var(--orange);font-size:1.35rem}
.dashboard-view .action-card .action-text{font-size:0.84rem;font-weight:700;letter-spacing:0;color:var(--white)}
.dashboard-view .action-card .action-sub{font-size:0.75rem;color:#a2a5aa;line-height:1.25;margin-top:2px}
.dashboard-view .section-panel{margin-bottom:0}
.dashboard-view .section-panel-header{margin-bottom:21px;padding-bottom:11px;border-bottom:1px solid #24282b}
.dashboard-view .section-panel-header h2{font-size:1.45rem;letter-spacing:1.7px}
.dashboard-view .section-panel-header h2 span{color:var(--orange)}
.dashboard-view .section-panel-header .section-sub{font-size:0.86rem;color:#a6a9ae;letter-spacing:0}
.dashboard-view .programs-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
.dashboard-view .program-card{min-height:110px;padding:19px 23px;background:linear-gradient(135deg,rgba(255,255,255,0.05),rgba(255,255,255,0.018));border:1px solid #303438;border-radius:6px;display:grid;grid-template-columns:1fr auto;grid-template-rows:auto 1fr;align-items:end;overflow:hidden}
.dashboard-view .program-card:hover{border-color:#3e4348;transform:none;box-shadow:inset 0 1px 0 rgba(255,255,255,0.035)}
.dashboard-view .program-card-img{display:block;height:auto;background:transparent;grid-column:1 / -1;position:static;overflow:visible}
.dashboard-view .program-card-img::before,.dashboard-view .program-card-img::after,.dashboard-view .program-card-body::before{display:none}
.dashboard-view .program-card-img .program-mark{width:45px;height:45px;border-radius:5px;background:rgba(255,90,31,0.07);border:1px solid rgba(255,90,31,0.22);box-shadow:none;font-size:1.25rem}
.dashboard-view .program-card-img .program-code{position:absolute;right:23px;bottom:20px;font-family:var(--font-heading);font-size:0.95rem;letter-spacing:1px;color:rgba(255,255,255,0.22)}
.dashboard-view .program-card-body{display:contents;padding:0}
.dashboard-view .program-card-body h3{grid-column:1;grid-row:2;margin:0;font-size:1.12rem;letter-spacing:1.1px;line-height:1.1}
.dashboard-view .program-card-body p,.dashboard-view .program-card-body .program-stat{display:none}
.dashboard-view .student-dashboard-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:25px}
.dashboard-view .student-dashboard-actions{display:flex;flex-direction:column;gap:12px}
.dashboard-view .student-dashboard-actions .action-card{margin-bottom:0!important}
.dashboard-view .student-dashboard-note{margin:0!important;padding:20px!important;text-align:left!important;border-radius:6px}
.dashboard-view .student-dashboard-note h2{font-size:1.25rem!important}
.dashboard-view .student-dashboard-note p{font-size:0.86rem!important;margin-bottom:0!important}
.dashboard-view .program-chart-summary{display:flex;flex-direction:column;align-items:flex-end;gap:2px;color:#a5a8ad;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.8px}
.dashboard-view .program-chart-summary strong{font-family:var(--font-heading);font-size:1rem;letter-spacing:1px;color:var(--orange);font-weight:700}
.dashboard-view .program-chart-summary select{height:30px;margin-top:8px;padding:4px 28px 4px 10px;background:#1d2022;border:1px solid #3a3f43;border-radius:4px;color:var(--white);font-size:0.78rem;font-weight:700;letter-spacing:0.6px;text-transform:uppercase}
.dashboard-view .program-chart-summary select:focus{border-color:var(--orange);box-shadow:0 0 0 3px var(--orange-glow)}
.dashboard-view .enrollment-trend-card{display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:18px;padding:18px;background:linear-gradient(135deg,rgba(255,255,255,0.05),rgba(255,255,255,0.018));border:1px solid #303438;border-radius:6px}
.dashboard-view .enrollment-trend-plot{min-width:0}
.dashboard-view .enrollment-trend-svg{display:block;width:100%;height:auto;min-height:220px}
.dashboard-view .trend-grid{stroke:rgba(255,255,255,0.09);stroke-width:1}
.dashboard-view .trend-axis-label{fill:#8e9297;font-family:var(--font-body);font-size:12px;font-weight:700}
.dashboard-view .trend-area{fill:rgba(255,90,31,0.13)}
.dashboard-view .trend-line{fill:none;stroke:var(--orange);stroke-width:4;stroke-linecap:round;stroke-linejoin:round;filter:drop-shadow(0 0 8px rgba(255,90,31,0.22))}
.dashboard-view .trend-point{fill:#ff8a3d;stroke:#151719;stroke-width:2}
.dashboard-view .trend-labels{display:grid;grid-auto-flow:column;grid-auto-columns:1fr;gap:8px;margin:0 16px 0 42px;color:#a4a7ac;font-size:0.73rem;text-align:center;text-transform:uppercase;letter-spacing:0.5px}
.dashboard-view .trend-labels .is-muted{color:transparent}
.dashboard-view .trend-insights{display:grid;align-content:center;gap:12px}
.dashboard-view .trend-insights div{padding:16px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.075);border-radius:5px}
.dashboard-view .trend-insights span{display:block;color:#9ca0a6;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:6px}
.dashboard-view .trend-insights strong{font-family:var(--font-heading);font-size:1.25rem;letter-spacing:1px;color:var(--white)}
.dashboard-view .enrollment-trend-empty{padding:22px;background:linear-gradient(135deg,rgba(255,255,255,0.05),rgba(255,255,255,0.018));border:1px dashed #303438;border-radius:6px;color:#a6a9ae}

/* ============================================ */
/* TABLES                                       */
/* ============================================ */
.table-controls{display:flex;flex-wrap:wrap;gap:20px;margin-bottom:23px;align-items:center}
.table-controls .search-input{flex:1;min-width:200px;height:50px;padding:12px 16px 12px 54px;background:rgba(35,37,39,0.74);border:1px solid #3b3f43;border-radius:4px;color:var(--white);font-size:1rem;transition:all 0.2s;position:relative}
.table-controls .search-input:focus{border-color:var(--orange);box-shadow:0 0 0 3px var(--orange-glow)}
.table-controls .search-wrap{position:relative;flex:1;min-width:200px}
.table-controls .search-wrap i{position:absolute;left:20px;top:50%;transform:translateY(-50%);color:#b8bbc0;font-size:1.1rem}
.table-controls .search-wrap input{width:100%;height:50px;padding:12px 16px 12px 54px;background:rgba(35,37,39,0.74);border:1px solid #3b3f43;border-radius:4px;color:var(--white);font-size:1rem;transition:all 0.2s}
.table-controls .search-wrap input:focus{border-color:var(--orange);box-shadow:0 0 0 3px var(--orange-glow)}
.table-controls .search-wrap input::placeholder{color:#9da0a6}
.table-controls select{height:50px;padding:12px 14px;background:rgba(35,37,39,0.74);border:1px solid #3b3f43;border-radius:4px;color:var(--white);font-size:0.96rem;transition:all 0.2s}
.table-controls select:focus{border-color:var(--orange)}
.table-controls>.btn{height:50px;min-width:144px;padding:0 28px;border-radius:5px;font-size:0.82rem;letter-spacing:1px}
.table-controls>.btn i{font-size:1rem}
.table-wrapper{overflow-x:auto;border:0;border-radius:0;background:transparent}
.data-table{width:100%;border-collapse:collapse;font-size:0.95rem}
.data-table thead{background:transparent}
.data-table th{padding:14px 18px 19px;text-align:left;font-weight:800;letter-spacing:1.6px;text-transform:uppercase;font-size:0.78rem;color:#c7c8cc;border-bottom:1px solid #303336;white-space:nowrap}
.data-table td{padding:14px 18px;border-bottom:1px solid #292c2f;color:#d9dadc;vertical-align:middle}
.data-table tbody tr{transition:background 0.15s}
.data-table tbody tr:hover{background:rgba(255,255,255,0.025)}
.data-table tbody tr.inactive-row{opacity:0.55}
.status-badge{display:inline-block;padding:3px 10px;border-radius:3px;font-size:0.75rem;font-weight:700;letter-spacing:1px;text-transform:uppercase}
.status-badge.active{background:rgba(255,90,31,0.15);color:var(--orange);border:1px solid rgba(255,90,31,0.3)}
.status-badge.inactive{background:var(--inactive-bg);color:var(--inactive-text);border:1px solid var(--light-gray)}
.table-actions{display:flex;gap:8px;flex-wrap:nowrap}
.data-table .btn-icon{width:40px;height:40px;padding:0;border-radius:5px;font-size:0.95rem}
.user-table{table-layout:fixed;min-width:980px}
.user-table th:nth-child(1),.user-table td:nth-child(1){width:19%}
.user-table th:nth-child(2),.user-table td:nth-child(2){width:22.5%}
.user-table th:nth-child(3),.user-table td:nth-child(3){width:14.5%}
.user-table th:nth-child(4),.user-table td:nth-child(4){width:16%}
.user-table th:nth-child(5),.user-table td:nth-child(5){width:15%}
.user-table th:nth-child(6),.user-table td:nth-child(6){width:13%}
.user-table .table-actions{gap:16px}
.audit-log-table{min-width:1080px}
.audit-log-table th:nth-child(1),.audit-log-table td:nth-child(1){width:15%}
.audit-log-table th:nth-child(2),.audit-log-table td:nth-child(2){width:20%}
.audit-log-table th:nth-child(3),.audit-log-table td:nth-child(3){width:10%}
.audit-log-table th:nth-child(4),.audit-log-table td:nth-child(4){width:15%}
.audit-log-table th:nth-child(5),.audit-log-table td:nth-child(5){width:18%}
.audit-cell-main{display:grid;gap:3px;font-weight:600;color:var(--white)}
.audit-cell-main span{font-weight:400;color:var(--muted);font-size:0.82rem}
.audit-details{color:#c7c9cc;font-size:0.86rem;line-height:1.45}
.audit-muted{color:var(--muted);font-size:0.86rem}

/* Empty state */
.empty-state{text-align:center;padding:60px 20px;color:var(--muted)}
.empty-state i{font-size:3rem;margin-bottom:16px;opacity:0.3}
.empty-state h3{font-family:var(--font-heading);font-size:1.4rem;letter-spacing:2px;color:var(--silver);margin-bottom:8px}
.empty-state p{font-size:0.92rem}

/* ============================================ */
/* PROFILE PAGE                                 */
/* ============================================ */
.profile-layout{display:grid;grid-template-columns:300px 1fr;gap:24px}
.profile-sidebar{background:var(--dark-gray);border:1px solid var(--mid-gray);border-radius:8px;padding:32px;text-align:center;position:relative;overflow:hidden}
.profile-sidebar::before{content:'';position:absolute;top:0;left:0;right:0;height:80px;background:linear-gradient(135deg,var(--mid-gray),var(--light-gray))}
.profile-avatar{width:90px;height:90px;border-radius:50%;background:var(--mid-gray);border:3px solid var(--orange);display:flex;align-items:center;justify-content:center;font-family:var(--font-heading);font-size:2rem;color:var(--orange);margin:30px auto 16px;position:relative;z-index:2;overflow:hidden}
.profile-name{font-family:var(--font-heading);font-size:1.5rem;letter-spacing:2px;color:var(--white);margin-bottom:4px}
.profile-email{color:var(--muted);font-size:0.88rem;margin-bottom:12px}
.profile-picture-actions{position:relative;z-index:2;margin:16px 0 14px;display:flex;flex-direction:column;align-items:center;gap:8px}
.profile-picture-actions .btn{width:100%;max-width:190px}
.profile-picture-help{color:var(--muted);font-size:0.78rem;line-height:1.3}
.profile-main{background:var(--dark-gray);border:1px solid var(--mid-gray);border-radius:8px;padding:32px}
.profile-main h2{font-family:var(--font-heading);font-size:1.6rem;letter-spacing:3px;color:var(--white);margin-bottom:20px;padding-bottom:12px;border-bottom:1px solid var(--mid-gray)}
.profile-main h2 span{color:var(--orange)}
.profile-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.profile-detail-item{padding:12px 0;border-bottom:1px solid var(--mid-gray)}
.profile-detail-item label{font-size:0.78rem;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);display:block;margin-bottom:4px}
.profile-detail-item span{font-size:1rem;color:var(--white);font-weight:500}

/* ============================================ */
/* STUDENT DASHBOARD SPECIAL                    */
/* ============================================ */
.student-profile-card{background:var(--dark-gray);border:1px solid var(--mid-gray);border-radius:8px;overflow:hidden;position:relative}
.student-profile-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--orange),transparent 70%)}
.student-profile-card-header{padding:28px 24px 16px;display:flex;align-items:center;gap:20px}
.student-profile-card-avatar{width:64px;height:64px;border-radius:50%;background:var(--mid-gray);border:2px solid var(--orange);display:flex;align-items:center;justify-content:center;font-family:var(--font-heading);font-size:1.6rem;color:var(--orange);flex-shrink:0}
.student-profile-card-info h3{font-family:var(--font-heading);font-size:1.6rem;letter-spacing:2px;color:var(--white)}
.student-profile-card-info p{color:var(--muted);font-size:0.88rem}
.student-profile-card-body{padding:0 24px 24px}
.student-profile-detail{display:grid;grid-template-columns:1fr 1fr;gap:12px 24px}
.spd-item{padding:10px 0;border-bottom:1px solid var(--mid-gray)}
.spd-item label{font-size:0.72rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted)}
.spd-item span{display:block;font-size:0.95rem;color:var(--white);font-weight:500;margin-top:2px}

/* ============================================ */
/* RESPONSIVE                                   */
/* ============================================ */
@media(max-width:1024px){
    .login-hero{padding:40px}
    .login-brand{width:min(340px,76vw)}
    .profile-layout{grid-template-columns:1fr}
    .dashboard-view .stats-grid,.dashboard-view.dashboard-compact .stats-grid,.dashboard-view .quick-actions,.dashboard-view.dashboard-compact .quick-actions,.dashboard-view .programs-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .dashboard-view .student-dashboard-grid{grid-template-columns:1fr}
}
@media(max-width:768px){
    #login-page{flex-direction:column}
    .login-hero{min-height:40vh;padding:30px}
    .login-brand{width:min(280px,80vw)}
    .login-tagline{font-size:1.1rem;letter-spacing:3px}
    .login-form-side{width:100%;min-width:unset;padding:30px}
    .hamburger{display:block}
    #sidebar{transform:translateX(-100%)}
    #sidebar.open{transform:translateX(0)}
    .sidebar-overlay.show{display:block}
    #main-content{margin-left:0}
    #app-header{padding:0 16px}
    .header-page-title{display:none}
    .header-right{gap:12px}
    .header-user-info{display:none}
    .content-section{padding:28px 20px 48px}
    .hero-banner{padding:0 0 28px;margin-bottom:26px}
    .hero-banner h1{font-size:2rem}
    .stats-grid{grid-template-columns:1fr 1fr}
    .quick-actions{grid-template-columns:1fr 1fr}
    #view-dashboard.content-section{padding:20px}
    .dashboard-view .stats-grid,.dashboard-view.dashboard-compact .stats-grid,.dashboard-view .quick-actions,.dashboard-view.dashboard-compact .quick-actions,.dashboard-view .programs-grid{grid-template-columns:1fr}
    .dashboard-view .hero-banner{padding:24px 20px}
    .dashboard-view .program-chart-summary{align-items:flex-start;margin-top:8px}
    .dashboard-view .enrollment-trend-card{grid-template-columns:1fr}
    .dashboard-view .trend-labels{margin-left:32px;font-size:0.68rem}
    .programs-grid,.staff-grid,.stories-grid{grid-template-columns:1fr}
    .approval-card-header{flex-direction:column}
    .approval-meta-grid{grid-template-columns:1fr}
    .form-row{grid-template-columns:1fr}
    .passkey-actions{flex-direction:column;align-items:stretch}
    .profile-detail-grid{grid-template-columns:1fr}
    .student-profile-detail{grid-template-columns:1fr}
    .table-controls{flex-direction:column;gap:12px}
    .table-controls .search-wrap,.table-controls>.btn{width:100%}
    .modal-box{max-width:95vw}
}
@media(max-width:480px){
    .stats-grid{grid-template-columns:1fr}
    .quick-actions{grid-template-columns:1fr}
    .story-card{flex-direction:column}
    .story-card-img{width:100%;min-height:120px}
}
</style>
</head>
<body>

<!-- Toast Container -->
<div id="toast-container" aria-live="polite"></div>

<!-- Confirmation Modal -->
<div id="confirm-modal" class="modal-overlay" role="dialog" aria-modal="true">
    <div class="modal-box" style="max-width:440px">
        <div class="modal-header">
            <h2 id="confirm-title">CONFIRM ACTION</h2>
            <button class="modal-close" data-close-modal="confirm" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p id="confirm-message" style="color:var(--silver);line-height:1.6"></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" data-close-modal="confirm">Cancel</button>
            <button id="confirm-btn" class="btn btn-danger btn-sm">Confirm</button>
        </div>
    </div>
</div>

<!-- Student Form Modal -->
<div id="student-modal" class="modal-overlay" role="dialog" aria-modal="true">
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="student-modal-title">ADD STUDENT</h2>
            <button class="modal-close" data-close-modal="student" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="student-modal-body"></div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" data-close-modal="student">Cancel</button>
            <button id="student-modal-save" class="btn btn-primary btn-sm">Save Record</button>
        </div>
    </div>
</div>

<!-- Approval Queue Modal -->
<div id="approval-modal" class="modal-overlay" role="dialog" aria-modal="true">
    <div class="modal-box" style="max-width:760px">
        <div class="modal-header">
            <h2>PENDING APPROVALS</h2>
            <button class="modal-close" data-close-modal="approval" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="approval-modal-body"></div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" data-close-modal="approval">Close</button>
        </div>
    </div>
</div>

<!-- Faculty Form Modal -->
<div id="user-modal" class="modal-overlay" role="dialog" aria-modal="true">
    <div class="modal-box" style="max-width:500px">
        <div class="modal-header">
            <h2 id="user-modal-title">ADD FACULTY</h2>
            <button class="modal-close" data-close-modal="user" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="user-modal-body"></div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" data-close-modal="user">Cancel</button>
            <button id="user-modal-save" class="btn btn-primary btn-sm">Save Faculty</button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- LOGIN PAGE                                   -->
<!-- ============================================ -->
<div id="login-page">
    <div class="login-hero">
        <div class="login-hero-content">
            <img class="brand-logo login-brand" src="assets/img/asct-logo.png" alt="ASCT.">
            <div class="login-tagline">AURORA STATE COLLEGE OF TECHNOLOGY</div>
        </div>
    </div>
    <div class="login-form-side">
        <h2 class="login-form-title">SIGN IN</h2>
        <p class="login-form-sub" id="login-form-sub">Access your ASCT dashboard and student records.</p>
        <div id="login-error" class="login-error"><i id="login-message-icon" class="fa-solid fa-circle-exclamation"></i><span id="login-error-msg"></span></div>
        <form id="login-form" autocomplete="off">
            <div class="form-group">
                <label for="login-email">Email Address</label>
                <input type="email" id="login-email" placeholder="Enter your email" required>
                <div class="error-msg">Please enter a valid email address</div>
            </div>
            <div class="form-group">
                <label for="login-password">Password</label>
                <input type="password" id="login-password" placeholder="Enter your password" required>
                <div class="error-msg">Password is required</div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block" style="margin-top:8px;font-family:var(--font-heading);letter-spacing:3px;font-size:1.2rem">
                <i class="fa-solid fa-right-to-bracket"></i> ACCESS SYSTEM
            </button>
            <div class="login-divider"><span>or</span></div>
            <button type="button" id="passkey-login-btn" class="btn btn-secondary btn-lg btn-block" style="font-family:var(--font-heading);letter-spacing:3px;font-size:1.2rem">
                <i class="fa-solid fa-fingerprint"></i> USE PASSKEY
            </button>
            <div class="login-inline-actions" style="justify-content:center">
                <button type="button" class="login-link-btn" id="signup-show-btn">Create Student Account</button>
            </div>
        </form>
        <form id="otp-form" autocomplete="off" style="display:none">
            <div class="login-note">
                A 6-digit verification code was sent to <strong id="otp-email"></strong>.
            </div>
            <div class="form-group">
                <label for="otp-code">Verification Code</label>
                <input type="text" id="otp-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="Enter 6-digit code" required>
                <div class="error-msg">Enter the 6-digit verification code</div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block" style="margin-top:8px;font-family:var(--font-heading);letter-spacing:3px;font-size:1.2rem">
                <i class="fa-solid fa-shield-halved"></i> VERIFY LOGIN
            </button>
            <div class="login-inline-actions">
                <button type="button" class="login-link-btn" id="otp-back-btn">Different Account</button>
                <button type="button" class="login-link-btn" id="otp-resend-btn">Resend Code</button>
            </div>
        </form>
        <form id="signup-form" autocomplete="off" style="display:none">
            <div class="login-note">
                Student accounts require email confirmation and admin approval before sign-in is available.
            </div>
            <div class="form-group">
                <label for="signup-name">Full Name</label>
                <input type="text" id="signup-name" placeholder="Enter your full name" required>
                <div class="error-msg">Full name is required</div>
            </div>
            <div class="form-group">
                <label for="signup-email">Email Address</label>
                <input type="email" id="signup-email" placeholder="email@asct.edu.ph" required>
                <div class="error-msg">Please enter a valid email address</div>
            </div>
            <div class="form-group">
                <label for="signup-password">Password</label>
                <input type="password" id="signup-password" placeholder="At least 8 characters" minlength="8" required>
                <div class="error-msg">Password must be at least 8 characters</div>
            </div>
            <div class="form-group">
                <label for="signup-password-confirm">Confirm Password</label>
                <input type="password" id="signup-password-confirm" placeholder="Repeat your password" minlength="8" required>
                <div class="error-msg">Passwords must match</div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block" style="margin-top:8px;font-family:var(--font-heading);letter-spacing:3px;font-size:1.2rem">
                <i class="fa-solid fa-envelope-circle-check"></i> SEND CONFIRMATION
            </button>
            <div class="login-inline-actions" style="justify-content:center">
                <button type="button" class="login-link-btn" id="signup-back-btn">Back to Sign In</button>
            </div>
        </form>
        <form id="signup-otp-form" autocomplete="off" style="display:none">
            <div class="login-note">
                A 6-digit confirmation code was sent to <strong id="signup-otp-email"></strong>.
            </div>
            <div class="form-group">
                <label for="signup-otp-code">Confirmation Code</label>
                <input type="text" id="signup-otp-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="Enter 6-digit code" required>
                <div class="error-msg">Enter the 6-digit confirmation code</div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block" style="margin-top:8px;font-family:var(--font-heading);letter-spacing:3px;font-size:1.2rem">
                <i class="fa-solid fa-shield-halved"></i> CONFIRM EMAIL
            </button>
            <div class="login-inline-actions">
                <button type="button" class="login-link-btn" id="signup-otp-back-btn">Edit Signup</button>
                <button type="button" class="login-link-btn" id="signup-otp-resend-btn">Resend Code</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- APP CONTAINER                                -->
<!-- ============================================ -->
<div id="app-container">
    <!-- Header -->
    <header id="app-header">
        <div class="header-left">
            <button class="hamburger" id="hamburger-btn" aria-label="Toggle menu"><i class="fa-solid fa-bars"></i></button>
            <img class="brand-logo header-brand" src="assets/img/asct-logo.png" alt="ASCT.">
            <div class="header-divider"></div>
            <div class="header-page-title" id="header-page-title">DASHBOARD</div>
        </div>
        <div class="header-right">
            <div class="header-user">
                <div class="header-avatar" id="header-avatar">A</div>
                <div class="header-user-info">
                    <div class="header-user-name" id="header-user-name">Admin</div>
                    <span class="role-badge admin" id="header-role-badge">ADMIN</span>
                </div>
            </div>
            <button class="btn-logout" id="logout-btn" aria-label="Logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</button>
        </div>
    </header>

    <!-- Sidebar Overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebar-overlay"></div>

    <!-- Sidebar -->
    <aside id="sidebar">
        <div class="sidebar-section">
            <div class="sidebar-section-title">Navigation</div>
            <ul class="sidebar-nav" id="sidebar-nav"></ul>
        </div>
        <div class="sidebar-footer">
            <div class="sidebar-footer-text">ASCT SIMS v1.0</div>
        </div>
    </aside>

    <!-- Main Content -->
    <main id="main-content">
        <section class="content-section" id="view-dashboard"></section>
        <section class="content-section" id="view-students"></section>
        <section class="content-section" id="view-users"></section>
        <section class="content-section" id="view-approval"></section>
        <section class="content-section" id="view-audit"></section>
        <section class="content-section" id="view-profile"></section>
        <section class="content-section" id="view-security"></section>
    </main>
</div>

<script src="assets/js/app.js"></script>
</body>
</html>

