<!-- 404 Not Found Page -->
@extends('frontend.index')
@section('content')

<link rel="stylesheet" href="{{ asset('css/website.css') }}">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- 404 Header -->
<div class="page-header" style="background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); min-height: 300px;">
    <div class="container-xl">
        <div style="text-align: center; color: white; padding: 80px 0;">
            <div style="font-size: 120px; font-weight: 800; margin-bottom: 20px; line-height: 1;">404</div>
            <h1 style="font-size: 48px; margin: 20px 0; font-weight: 700;">Page Not Found</h1>
            <p style="font-size: 18px; margin: 0; color: rgba(255,255,255,0.9); max-width: 600px; margin-left: auto; margin-right: auto;">
                We're sorry, but the page you're looking for doesn't exist. It may have been removed or the URL might be incorrect.
            </p>
        </div>
    </div>
</div>

<!-- 404 Content -->
<main style="padding: 80px 0;">
    <div class="container-xl">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 60px; align-items: center;">
            <!-- Left: Illustration -->
            <div style="text-align: center;">
                <div style="width: 300px; height: 300px; margin: 0 auto; background: linear-gradient(135deg, var(--light-bg), var(--lighter-bg)); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-search" style="font-size: 120px; color: var(--text-secondary); opacity: 0.3;"></i>
                </div>
            </div>
            
            <!-- Right: Actions -->
            <div>
                <h2 style="font-size: 32px; font-weight: 700; color: var(--primary-color); margin-bottom: 20px;">
                    What Can We Help You With?
                </h2>
                
                <p style="font-size: 16px; color: var(--text-secondary); margin-bottom: 30px; line-height: 1.6;">
                    You could try searching for what you're looking for:
                </p>
                
                <!-- Search Box -->
                <form method="GET" action="/" style="margin-bottom: 40px;">
                    <div style="display: flex; gap: 10px;">
                        <input 
                            type="text" 
                            name="q" 
                            placeholder="Search..." 
                            style="flex: 1; padding: 14px 20px; border: 2px solid var(--border-color); border-radius: var(--radius-md); font-size: 16px; transition: all 0.3s ease;"
                            onfocus="this.style.borderColor='var(--secondary-color)'"
                            onblur="this.style.borderColor='var(--border-color)'"
                        >
                        <button 
                            type="submit" 
                            style="padding: 14px 32px; background: var(--secondary-color); color: white; border: none; border-radius: var(--radius-md); font-weight: 600; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px;"
                            onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px rgba(74, 144, 226, 0.3)'"
                            onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'"
                        >
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </form>
                
                <!-- Quick Links -->
                <div style="margin-bottom: 40px;">
                    <p style="font-size: 14px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 16px; letter-spacing: 0.5px;">
                        Or navigate to:
                    </p>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <a href="/" style="padding: 12px 16px; background: var(--light-bg); color: var(--primary-color); border: 1px solid var(--border-color); border-radius: var(--radius-md); text-decoration: none; font-weight: 500; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px;"
                           onmouseover="this.style.background='var(--lighter-bg)'; this.style.color='var(--secondary-color)'"
                           onmouseout="this.style.background='var(--light-bg)'; this.style.color='var(--primary-color)'"
                        >
                            <i class="fas fa-home"></i> Home
                        </a>
                        <a href="/about-us" style="padding: 12px 16px; background: var(--light-bg); color: var(--primary-color); border: 1px solid var(--border-color); border-radius: var(--radius-md); text-decoration: none; font-weight: 500; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px;"
                           onmouseover="this.style.background='var(--lighter-bg)'; this.style.color='var(--secondary-color)'"
                           onmouseout="this.style.background='var(--light-bg)'; this.style.color='var(--primary-color)'"
                        >
                            <i class="fas fa-info-circle"></i> About Us
                        </a>
                        <a href="/academic-programmes" style="padding: 12px 16px; background: var(--light-bg); color: var(--primary-color); border: 1px solid var(--border-color); border-radius: var(--radius-md); text-decoration: none; font-weight: 500; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px;"
                           onmouseover="this.style.background='var(--lighter-bg)'; this.style.color='var(--secondary-color)'"
                           onmouseout="this.style.background='var(--light-bg)'; this.style.color='var(--primary-color)'"
                        >
                            <i class="fas fa-book"></i> Programs
                        </a>
                        <a href="/contact-us" style="padding: 12px 16px; background: var(--light-bg); color: var(--primary-color); border: 1px solid var(--border-color); border-radius: var(--radius-md); text-decoration: none; font-weight: 500; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px;"
                           onmouseover="this.style.background='var(--lighter-bg)'; this.style.color='var(--secondary-color)'"
                           onmouseout="this.style.background='var(--light-bg)'; this.style.color='var(--primary-color)'"
                        >
                            <i class="fas fa-envelope"></i> Contact Us
                        </a>
                    </div>
                </div>
                
                <!-- Error Code -->
                <p style="font-size: 12px; color: var(--text-light); margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--border-color);">
                    <strong>Error Code:</strong> 404 Page Not Found
                </p>
            </div>
        </div>
    </div>
</main>

<!-- Responsive -->
<style>
@media (max-width: 768px) {
    [style*="grid-template-columns: 1fr 1fr"] {
        grid-template-columns: 1fr !important;
    }
    
    [style*="font-size: 120px"] {
        font-size: 80px !important;
    }
    
    [style*="font-size: 48px"] {
        font-size: 36px !important;
    }
    
    [style*="font-size: 32px"] {
        font-size: 24px !important;
    }
}
</style>

@endsection
