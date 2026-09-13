PORTNAME=	continuum
DISTVERSIONPREFIX=	v
DISTVERSION=	0.2.2
CATEGORIES=	www
MASTER_SITES=	https://pacyworld.dev/buenapp/continuum/archive/
PKGNAMEPREFIX=	${PHP_PKGNAMEPREFIX}
DISTNAME=	v${DISTVERSION}
DIST_SUBDIR=	${PORTNAME}

MAINTAINER=	justin@morante.net
COMMENT=	Blackboard coordination MCP server for multi-agent orchestration
WWW=		https://pacyworld.dev/buenapp/continuum

LICENSE=	BSD3CLAUSE
LICENSE_FILE=	${WRKSRC}/LICENSE

USES=		php:flavors
USE_PHP=	ctype curl filter mbstring

WWWDIR=		${PREFIX}/www/${PORTNAME}
NO_ARCH=	yes
NO_BUILD=	yes
SUB_FILES=	pkg-message
SUB_LIST=	WWWDIR=${WWWDIR} EXAMPLESDIR=${EXAMPLESDIR}

WRKSRC=		${WRKDIR}/continuum

OPTIONS_DEFINE=	EXAMPLES

do-install:
	@${MKDIR} ${STAGEDIR}${WWWDIR}
	@${MKDIR} ${STAGEDIR}${WWWDIR}/bin
	@${MKDIR} ${STAGEDIR}${WWWDIR}/classes/Continuum/Bridge
	@${MKDIR} ${STAGEDIR}${WWWDIR}/classes/Continuum/Storage
	@${MKDIR} ${STAGEDIR}${WWWDIR}/config
	@${MKDIR} ${STAGEDIR}${WWWDIR}/includes
	@${MKDIR} ${STAGEDIR}${WWWDIR}/libraries/Enchilada/Config
	@${MKDIR} ${STAGEDIR}${WWWDIR}/libraries/Enchilada/Tortilla
	@${MKDIR} ${STAGEDIR}${WWWDIR}/libraries/EnchiladaHTTP
	@${MKDIR} ${STAGEDIR}${WWWDIR}/libraries/EnchiladaMCP
	@${MKDIR} ${STAGEDIR}${WWWDIR}/libraries/EnchiladaOTLP
	@${MKDIR} ${STAGEDIR}${WWWDIR}/public
	@${MKDIR} ${STAGEDIR}${WWWDIR}/system
	${INSTALL_SCRIPT} ${WRKSRC}/src/bin/mcp-stdio ${STAGEDIR}${WWWDIR}/bin/
	${INSTALL_SCRIPT} ${WRKSRC}/src/bin/smoke-test ${STAGEDIR}${WWWDIR}/bin/
	${INSTALL_DATA} ${WRKSRC}/src/classes/Continuum/*.php ${STAGEDIR}${WWWDIR}/classes/Continuum/
	${INSTALL_DATA} ${WRKSRC}/src/classes/Continuum/Bridge/*.php ${STAGEDIR}${WWWDIR}/classes/Continuum/Bridge/
	${INSTALL_DATA} ${WRKSRC}/src/classes/Continuum/Storage/*.php ${STAGEDIR}${WWWDIR}/classes/Continuum/Storage/
	${INSTALL_DATA} ${WRKSRC}/src/config/settings.ini.sample ${STAGEDIR}${WWWDIR}/config/
	${INSTALL_DATA} ${WRKSRC}/src/config/instructions.txt ${STAGEDIR}${WWWDIR}/config/
	${INSTALL_DATA} ${WRKSRC}/src/includes/*.php ${STAGEDIR}${WWWDIR}/includes/
	${INSTALL_DATA} ${WRKSRC}/src/libraries/Enchilada/Config/*.php ${STAGEDIR}${WWWDIR}/libraries/Enchilada/Config/
	${INSTALL_DATA} ${WRKSRC}/src/libraries/Enchilada/Tortilla/*.php ${STAGEDIR}${WWWDIR}/libraries/Enchilada/Tortilla/
	${INSTALL_DATA} ${WRKSRC}/src/libraries/EnchiladaHTTP/*.php ${STAGEDIR}${WWWDIR}/libraries/EnchiladaHTTP/
	${INSTALL_DATA} ${WRKSRC}/src/libraries/EnchiladaMCP/*.php ${STAGEDIR}${WWWDIR}/libraries/EnchiladaMCP/
	${INSTALL_DATA} ${WRKSRC}/src/libraries/EnchiladaOTLP/*.php ${STAGEDIR}${WWWDIR}/libraries/EnchiladaOTLP/
	${INSTALL_DATA} ${WRKSRC}/src/public/index.php ${STAGEDIR}${WWWDIR}/public/
	${INSTALL_DATA} ${WRKSRC}/src/system/app.conf.php ${STAGEDIR}${WWWDIR}/system/
	${INSTALL_DATA} ${WRKSRC}/src/system/autoload.inc.php ${STAGEDIR}${WWWDIR}/system/

post-install-EXAMPLES-on:
	@${MKDIR} ${STAGEDIR}${EXAMPLESDIR}
	${INSTALL_DATA} ${WRKSRC}/etc/apache24/continuum.conf.sample ${STAGEDIR}${EXAMPLESDIR}/

.include <bsd.port.mk>
