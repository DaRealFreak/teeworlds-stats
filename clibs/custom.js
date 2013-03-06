$(function() {

	//===== Multiple select with dropdown =====//

	$(".chzn-select").chosen();


	//===== Accordion =====//

	$('div.menu_body:eq(0)').show();
	$('.acc .head:eq(0)').show().css({color:"#2B6893"});

	$(".acc .head").click(function() {
		$(this).css({color:"#2B6893"}).next("div.menu_body").slideToggle(300).siblings("div.menu_body").slideUp("slow");
		$(this).siblings().css({color:"#404040"});
	});


	//===== ToTop =====//

	$().UItoTop({ easingType: 'easeOutQuart' });


	//===== Contacts list =====//

	$('#myList').listnav({
		initLetter: 'a',
		includeAll: true,
		includeOther: true,
		flagDisabled: true,
		noMatchText: 'Nothing matched your filter, please click another letter.',
		prefixes: ['the','a'] ,
	});


	//===== Form elements styling =====//

	$("select, input:checkbox, input:radio, input:file").uniform();

	//===== Tooltip =====//

	$('.leftDir').tipsy({fade: true, gravity: 'e'});
	$('.rightDir').tipsy({fade: true, gravity: 'w'});
	$('.topDir').tipsy({fade: true, gravity: 's'});
	$('.botDir').tipsy({fade: true, gravity: 'n'});


	//===== Information boxes =====//

	$(".hideit").click(function() {
		$(this).fadeTo(200, 0.00, function(){ //fade
			$(this).slideUp(300, function() { //slide up
				$(this).remove(); //then remove from the DOM
			});
		});
	});


	//===== Left navigation submenu animation =====//

	$("ul.sub li a").hover(function() {
	$(this).stop().animate({ color: "#3a6fa5" }, 400);
	},function() {
	$(this).stop().animate({ color: "#494949" }, 400);
	});


	//===== Breadcrumbs =====//

	$("#breadCrumb").jBreadCrumb();


	//===== Autofocus =====//

	$('.autoF').focus();


	//===== Tabs =====//

	$.fn.simpleTabs = function(){

		//Default Action
		$(this).find(".tab_content").hide(); //Hide all content
		$(this).find("ul.tabs li:first").addClass("activeTab").show(); //Activate first tab
		$(this).find(".tab_content:first").show(); //Show first tab content

		//On Click Event
		$("ul.tabs li").click(function() {
			$(this).parent().parent().find("ul.tabs li").removeClass("activeTab"); //Remove any "active" class
			$(this).addClass("activeTab"); //Add "active" class to selected tab
			$(this).parent().parent().find(".tab_content").hide(); //Hide all tab content
			var activeTab = $(this).find("a").attr("href"); //Find the rel attribute value to identify the active tab + content
			$(activeTab).show(); //Fade in the active content
			return false;
		});

	};//end function

	$("div[class^='widget']").simpleTabs(); //Run function on any div with class name of "Simple Tabs"


	//===== User nav dropdown =====//

	$('.dd').click(function () {
		$('ul.menu_body').slideToggle(200);
	});

	$(document).bind('click', function(e) {
	var $clicked = $(e.target);
	if (! $clicked.parents().hasClass("dd"))
		$("ul.menu_body").slideUp(200);
	});





	$('.acts').click(function () {
		$('ul.actsBody').slideToggle(100);
	});


	//===== Collapsible elements management =====//

	$('.exp').collapsible({
		defaultOpen: 'current',
		cookieName: 'navAct',
		cssOpen: 'active corner',
		cssClose: 'inactive',
		speed: 300
	});

	$('.opened').collapsible({
		defaultOpen: 'opened,toggleOpened',
		cssOpen: 'inactive',
		cssClose: 'normal',
		speed: 200
	});

	$('.closed').collapsible({
		defaultOpen: '',
		cssOpen: 'inactive',
		cssClose: 'normal',
		speed: 200
	});








});
