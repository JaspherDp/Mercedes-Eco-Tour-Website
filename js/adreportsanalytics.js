(function(){
  'use strict';
  const data=window.reportAnalyticsData||{};
  const reduced=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const colors={green:'#287a63',greenSoft:'rgba(40,122,99,.12)',blue:'#4b7fca',blueSoft:'rgba(75,127,202,.11)',gold:'#d9a441',red:'#c85a64',gray:'#9aa8a3'};
  const baseFont={family:'Inter, sans-serif',size:11};
  Chart.defaults.font=baseFont; Chart.defaults.color='#71837c'; Chart.defaults.animation.duration=reduced?0:650;
  const money=value=>'₱'+Number(value||0).toLocaleString('en-PH',{maximumFractionDigits:0});
  const tooltip={backgroundColor:'#173c32',titleFont:{family:'Inter',size:12,weight:'700'},bodyFont:{family:'Inter',size:11},padding:12,cornerRadius:9,displayColors:true,boxWidth:8,boxHeight:8};
  const grid={color:'rgba(43,83,70,.07)',drawBorder:false};
  const performance=document.getElementById('performanceChart');
  if(performance){
    const hasData=(data.bookings||[]).some(Number)||(data.revenue||[]).some(Number);
    const empty=performance.parentElement.querySelector('.chart-empty'); if(empty) empty.hidden=hasData;
    new Chart(performance,{type:'bar',data:{labels:data.labels||[],datasets:[
      {type:'bar',label:'Bookings',data:data.bookings||[],borderColor:colors.green,backgroundColor:'rgba(40,122,99,.76)',borderWidth:0,borderRadius:5,borderSkipped:false,maxBarThickness:20,yAxisID:'y',order:2},
      {type:'line',label:'Revenue',data:data.revenue||[],borderColor:colors.blue,backgroundColor:colors.blueSoft,fill:false,tension:.32,pointBackgroundColor:'#fff',pointBorderColor:colors.blue,pointBorderWidth:2,pointRadius:(data.labels||[]).length>35?0:2.5,pointHoverRadius:5,borderWidth:2.2,yAxisID:'y1',order:1}
    ]},options:{responsive:true,maintainAspectRatio:false,layout:{padding:{top:8}},interaction:{mode:'index',intersect:false},plugins:{legend:{display:false},tooltip:{...tooltip,callbacks:{label:c=>c.dataset.yAxisID==='y1'?' Revenue: '+money(c.raw):' Bookings: '+c.raw}}},scales:{x:{grid:{display:false},ticks:{maxTicksLimit:8,maxRotation:0,font:{size:10}}},y:{beginAtZero:true,grace:'8%',grid,ticks:{precision:0,maxTicksLimit:5,font:{size:10}},border:{display:false}},y1:{beginAtZero:true,grace:'8%',position:'right',grid:{display:false},ticks:{callback:money,maxTicksLimit:5,font:{size:10}},border:{display:false}}}}});
  }
  const typeCanvas=document.getElementById('typeChart');
  if(typeCanvas)new Chart(typeCanvas,{type:'doughnut',data:{labels:data.typeLabels||[],datasets:[{data:data.types||[],backgroundColor:[colors.green,colors.blue,colors.gold,'#875fba'],borderColor:'#fff',borderWidth:4,hoverOffset:4}]},options:{responsive:true,maintainAspectRatio:false,cutout:'72%',plugins:{legend:{display:false},tooltip:{...tooltip,callbacks:{label:c=>' '+c.label+': '+c.raw}}}}});
  const destination=document.getElementById('destinationChart');
  if(destination)new Chart(destination,{type:'bar',data:{labels:data.destinations||[],datasets:[{label:'Bookings',data:data.destinationValues||[],backgroundColor:['#287a63','#3d8c76','#5ba08b','#7bb3a2','#9bc6b8','#bbd9cf'],borderRadius:6,borderSkipped:false,barPercentage:.72}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,layout:{padding:{right:10}},plugins:{legend:{display:false},tooltip},scales:{x:{beginAtZero:true,grace:'5%',grid,ticks:{precision:0,maxTicksLimit:5,font:{size:10}},border:{display:false}},y:{grid:{display:false},ticks:{font:{size:10},callback:function(v){const label=this.getLabelForValue(v);return label.length>28?label.slice(0,26)+'…':label}},border:{display:false}}}}});
  const statusPalette=[colors.green,colors.blue,colors.red,colors.gold,colors.gray];
  const status=document.getElementById('statusChart');
  if(status)new Chart(status,{type:'doughnut',data:{labels:data.statusLabels||[],datasets:[{data:data.statusValues||[],backgroundColor:statusPalette,borderColor:'#fff',borderWidth:3}]},options:{responsive:true,maintainAspectRatio:false,cutout:'66%',plugins:{legend:{display:false},tooltip}}});
  const legend=document.getElementById('statusLegend');
  if(legend){const total=(data.statusValues||[]).reduce((a,b)=>a+Number(b),0);legend.innerHTML=(data.statusLabels||[]).map((label,i)=>`<div class="status-legend-row"><i style="background:${statusPalette[i%statusPalette.length]}"></i><span>${String(label).replace(/[&<>"']/g,s=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[s]))}</span><b>${data.statusValues[i]||0} · ${total?Math.round((data.statusValues[i]||0)/total*100):0}%</b></div>`).join('')||'<span class="status-legend-row">No booking data</span>';}
  const form=document.getElementById('reportFilters'); const range=document.getElementById('rangeInput');
  if(form&&range){
    form.querySelectorAll('input[type="date"]').forEach(input=>input.addEventListener('change',()=>{range.value='custom'}));
    form.querySelectorAll('[data-range]').forEach(button=>button.addEventListener('click',()=>{range.value=button.dataset.range;form.requestSubmit()}));
    const bookingType=document.getElementById('bookingTypeFilter');
    const hotelScope=document.getElementById('hotelScopeToggle');
    const syncHotelScope=()=>{
      if(!bookingType||!hotelScope||bookingType.value==='all')return;
      hotelScope.checked=bookingType.value==='hotel';
    };
    bookingType?.addEventListener('change',syncHotelScope);
    form.addEventListener('submit',syncHotelScope);
  }
  const detailsDialog=document.getElementById('reportDetailDialog');
  const openDetails=()=>{
    if(!detailsDialog)return;
    if(typeof detailsDialog.showModal==='function')detailsDialog.showModal();
    else detailsDialog.setAttribute('open','');
  };
  const closeDetails=()=>{
    if(!detailsDialog)return;
    if(typeof detailsDialog.close==='function')detailsDialog.close();
    else detailsDialog.removeAttribute('open');
  };
  document.getElementById('openDetailedReport')?.addEventListener('click',openDetails);
  document.querySelectorAll('[data-open-details]').forEach(button=>button.addEventListener('click',openDetails));
  document.querySelectorAll('[data-close-details]').forEach(button=>button.addEventListener('click',closeDetails));
  detailsDialog?.addEventListener('click',event=>{if(event.target===detailsDialog)closeDetails()});

  const tourismDialog=document.getElementById('tourismPreviewDialog');
  const tourismFrame=tourismDialog?.querySelector('iframe');
  const tourismLoading=document.getElementById('tourismPreviewLoading');
  const tourismFilter=document.getElementById('tourismReportFilter');
  const tourismFrom=document.getElementById('tourismReportFrom');
  const tourismTo=document.getElementById('tourismReportTo');
  const tourismPeriodLabel=document.getElementById('tourismReportPeriodLabel');
  const tourismExcelDownload=document.getElementById('tourismExcelDownload');
  const tourismPdfDownload=document.getElementById('tourismPdfDownload');
  const tourismUrl=(format,disposition)=>{
    const query=new URLSearchParams({from:tourismFrom?.value||'',to:tourismTo?.value||'',format});
    if(disposition)query.set('disposition',disposition);
    return (tourismFilter?.dataset.endpoint||'tourism_report.php')+'?'+query.toString();
  };
  const tourismDateLabel=value=>new Date(value+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
  const refreshTourismReport=()=>{
    if(!tourismFrame||!tourismFrom||!tourismTo)return;
    tourismTo.setCustomValidity(tourismFrom.value>tourismTo.value?'The end date must be on or after the start date.':'');
    if(!tourismFrom.reportValidity()||!tourismTo.reportValidity())return;
    if(tourismLoading)tourismLoading.hidden=false;
    tourismFrame.src=tourismUrl('pdf','inline')+'&refresh='+Date.now();
    if(tourismExcelDownload)tourismExcelDownload.href=tourismUrl('xlsx','');
    if(tourismPdfDownload)tourismPdfDownload.href=tourismUrl('pdf','download');
    if(tourismPeriodLabel)tourismPeriodLabel.textContent=tourismDateLabel(tourismFrom.value)+' – '+tourismDateLabel(tourismTo.value)+' · Completed visits by service date';
  };
  const openTourismReport=()=>{
    if(!tourismDialog||!tourismFrame)return;
    if(!tourismFrame.hasAttribute('src'))tourismFrame.src=tourismFrame.dataset.src||'';
    if(typeof tourismDialog.showModal==='function')tourismDialog.showModal();
    else tourismDialog.setAttribute('open','');
  };
  const closeTourismReport=()=>{
    if(!tourismDialog)return;
    if(typeof tourismDialog.close==='function')tourismDialog.close();
    else tourismDialog.removeAttribute('open');
  };
  document.getElementById('tourismReportButton')?.addEventListener('click',openTourismReport);
  tourismFilter?.addEventListener('submit',event=>{event.preventDefault();refreshTourismReport()});
  tourismFrom?.addEventListener('change',()=>tourismTo?.setCustomValidity(''));
  tourismTo?.addEventListener('change',()=>tourismTo.setCustomValidity(''));
  document.querySelectorAll('[data-close-tourism-preview]').forEach(button=>button.addEventListener('click',closeTourismReport));
  tourismDialog?.addEventListener('click',event=>{if(event.target===tourismDialog)closeTourismReport()});
  tourismFrame?.addEventListener('load',()=>{if(tourismLoading)tourismLoading.hidden=true});

  const closeExpandedCard=()=>{
    const card=document.querySelector('.report-card.is-expanded');
    if(!card)return;
    card.classList.remove('is-expanded'); document.body.classList.remove('chart-expanded');
    const button=card.querySelector('[data-chart-expand]');
    if(button){button.title='Expand chart';button.setAttribute('aria-label',button.getAttribute('aria-label')?.replace('Collapse','Expand')||'Expand chart')}
    requestAnimationFrame(()=>card.querySelectorAll('canvas').forEach(canvas=>Chart.getChart(canvas)?.resize()));
  };
  document.querySelectorAll('[data-chart-expand]').forEach(button=>button.addEventListener('click',()=>{
    const card=button.closest('.report-card'); if(!card)return;
    const isExpanded=card.classList.contains('is-expanded'); closeExpandedCard();
    if(!isExpanded){card.classList.add('is-expanded');document.body.classList.add('chart-expanded');button.title='Collapse chart';button.setAttribute('aria-label',(button.getAttribute('aria-label')||'Expand chart').replace('Expand','Collapse'));requestAnimationFrame(()=>card.querySelectorAll('canvas').forEach(canvas=>Chart.getChart(canvas)?.resize()))}
  }));
  document.querySelectorAll('[data-chart-download]').forEach(button=>button.addEventListener('click',()=>{
    const canvas=document.getElementById(button.dataset.chartDownload||''); if(!canvas)return;
    const exportCanvas=document.createElement('canvas');exportCanvas.width=canvas.width;exportCanvas.height=canvas.height;
    const context=exportCanvas.getContext('2d');context.fillStyle='#ffffff';context.fillRect(0,0,exportCanvas.width,exportCanvas.height);context.drawImage(canvas,0,0);
    const link=document.createElement('a');link.download=(button.dataset.filename||'analytics-chart')+'.png';link.href=exportCanvas.toDataURL('image/png');link.click();
  }));
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&document.querySelector('.report-card.is-expanded')){event.preventDefault();closeExpandedCard()}});
  document.getElementById('printReport')?.addEventListener('click',()=>window.print());
  document.getElementById('printDetailedReport')?.addEventListener('click',()=>window.print());
})();
